"""The weighted scorer: reproducible, explainable, and never a model's opinion."""

from __future__ import annotations

import pytest
from job_agent_domain.enums import MatchRouting, RemoteType, Seniority
from job_agent_matching.evidence import EvidenceKind
from job_agent_matching.preferences import SearchPreferences
from job_agent_matching.scoring import WEIGHTS, inputs_hash, route, score_job
from job_agent_matching.types import CandidateView, JobView


def job(**overrides: object) -> JobView:
    base = {
        "id": "j1",
        "company": "Northwind Systems",
        "title": "Senior Backend Engineer",
        "normalized_title": "backend engineer",
        "seniority": Seniority.SENIOR,
        "description": "Build microservices on Kubernetes. Mentor two engineers.",
        "required_skills": ["Python", "FastAPI", "PostgreSQL"],
        "preferred_skills": ["Kubernetes"],
        "responsibilities": ["Design and ship backend services"],
        "country": "Jordan",
        "city": "Amman",
        "remote_type": RemoteType.REMOTE,
        "content_hash": "a" * 64,
    }
    return JobView.model_validate(base | overrides)


def candidate(**overrides: object) -> CandidateView:
    base = {
        "profile_id": "p1",
        "headline": "Engineering Lead / Senior Backend Engineer",
        "country": "Jordan",
        "years_experience": 7.0,
        "seniority": Seniority.SENIOR,
        "skills": {
            "python": "f1",
            "fastapi": "f2",
            "postgresql": "f3",
            "kubernetes": "f4",
            "docker": "f5",
        },
        "roles": {"r1": "Engineering Lead at Northwind Systems"},
        "achievements": {"a1": "Led a team of six engineers and mentored two juniors"},
    }
    return CandidateView.model_validate(base | overrides)


# --- shape ------------------------------------------------------------------


def test_weights_match_the_plan() -> None:
    assert WEIGHTS == {
        "role_fit": 0.25,
        "required_skills": 0.25,
        "seniority": 0.15,
        "architecture_cloud": 0.15,
        "leadership_domain": 0.10,
        "location_auth_comp": 0.10,
    }
    assert sum(WEIGHTS.values()) == pytest.approx(1.0)


def test_a_score_is_the_sum_of_its_dimensions() -> None:
    result = score_job(job(), candidate())
    assert result.score == pytest.approx(
        sum(dimension.contribution for dimension in result.dimensions), abs=0.01
    )
    assert 0 <= result.score <= 100


def test_a_strong_match_routes_to_high_priority() -> None:
    result = score_job(job(), candidate())
    assert result.score >= 80
    assert result.routing is MatchRouting.HIGH_PRIORITY


def test_an_unrelated_role_scores_low() -> None:
    unrelated = job(
        title="Senior Dental Hygienist",
        normalized_title="dental hygienist",
        description="Clean teeth and advise patients.",
        required_skills=["Dental hygiene", "Patient care"],
        preferred_skills=[],
        responsibilities=["See patients"],
    )
    assert score_job(unrelated, candidate()).score < 60


# --- reproducibility --------------------------------------------------------


def test_the_same_inputs_always_produce_the_same_score() -> None:
    """Phase 3 acceptance."""
    first = score_job(job(), candidate())
    second = score_job(job(), candidate())
    assert first.score == second.score
    assert first.inputs_hash == second.inputs_hash
    assert first.breakdown() == second.breakdown()


def test_changed_job_text_changes_the_inputs_hash() -> None:
    preferences = SearchPreferences()
    before = inputs_hash(job(), candidate(), preferences)
    after = inputs_hash(job(content_hash="b" * 64), candidate(), preferences)
    assert before != after


def test_changed_preferences_change_the_inputs_hash() -> None:
    before = inputs_hash(job(), candidate(), SearchPreferences())
    after = inputs_hash(job(), candidate(), SearchPreferences(target_countries=["Jordan"]))
    assert before != after


def test_a_different_embedding_model_is_a_different_score() -> None:
    """The same text scored with another model must not reuse a cached number."""
    a = inputs_hash(job(), candidate(), SearchPreferences(), embedding_model="minilm")
    b = inputs_hash(job(), candidate(), SearchPreferences(), embedding_model="bge-large")
    assert a != b


# --- evidence ---------------------------------------------------------------


def test_every_matched_and_missing_requirement_carries_evidence() -> None:
    """Phase 3 acceptance."""
    result = score_job(job(required_skills=["Python", "Rust"]), candidate())
    matched = result.requirements(EvidenceKind.MATCHED_REQUIREMENT)
    missing = result.requirements(EvidenceKind.MISSING_REQUIREMENT)

    assert matched and missing
    assert all(item.reference for item in matched + missing)
    assert any(item.requirement == "Rust" for item in missing)
    python = next(item for item in matched if item.requirement == "Python")
    assert python.reference == "fact:f1"
    assert python.source == "cv"


def test_a_skill_proven_in_a_role_description_still_counts() -> None:
    """A CV often proves a skill in a sentence rather than in the skills list."""
    result = score_job(
        job(required_skills=["Terraform"]),
        candidate(skills={}, roles={"r1": "Built infrastructure with Terraform and Docker"}),
    )
    matched = result.requirements(EvidenceKind.MATCHED_REQUIREMENT)
    assert any(item.requirement == "Terraform" and item.reference == "fact:r1" for item in matched)


def test_skill_aliases_are_resolved() -> None:
    result = score_job(
        job(required_skills=["NodeJS", "Postgres", "K8s"], preferred_skills=[]),
        candidate(skills={"node.js": "f1", "postgresql": "f2", "kubernetes": "f3"}),
    )
    missing_skills = [
        item
        for item in result.requirements(EvidenceKind.MISSING_REQUIREMENT)
        if item.dimension == "required_skills"
    ]
    assert missing_skills == []


def test_a_posting_with_no_readable_requirements_is_uncertain_not_zero() -> None:
    """Scoring it zero would bury every job from a board that writes prose."""
    result = score_job(job(required_skills=[], preferred_skills=[]), candidate())
    skills = next(d for d in result.dimensions if d.name == "required_skills")
    assert skills.score == 0.5
    assert result.requirements(EvidenceKind.UNCERTAIN)


# --- hard blockers ----------------------------------------------------------


def test_a_hard_blocker_rejects_but_keeps_the_score_visible() -> None:
    """An 88 blocked on location is different from an 88 blocked on pay."""
    result = score_job(
        job(remote_type=RemoteType.ONSITE, country="Germany"),
        candidate(),
        SearchPreferences(target_countries=["Jordan"]),
    )
    assert result.routing is MatchRouting.REJECTED
    assert result.rejected
    assert result.score > 0
    blockers = result.requirements(EvidenceKind.HARD_BLOCKER)
    assert blockers and blockers[0].reference == "job:j1#country"


@pytest.mark.parametrize(
    ("score", "expected"),
    [
        (95.0, MatchRouting.HIGH_PRIORITY),
        (80.0, MatchRouting.HIGH_PRIORITY),
        (79.9, MatchRouting.NORMAL_REVIEW),
        (70.0, MatchRouting.NORMAL_REVIEW),
        (69.9, MatchRouting.POSSIBLE_MATCH),
        (60.0, MatchRouting.POSSIBLE_MATCH),
        (59.9, MatchRouting.ARCHIVED),
    ],
)
def test_routing_thresholds_match_the_plan(score: float, expected: MatchRouting) -> None:
    assert route(score, []) is expected


# --- individual dimensions --------------------------------------------------


def test_a_step_up_in_seniority_is_reported_as_a_gap() -> None:
    result = score_job(job(seniority=Seniority.PRINCIPAL), candidate())
    gaps = [item for item in result.evidence if item.kind is EvidenceKind.GAP]
    assert any((item.detail or "") == "a step up" for item in gaps)


def test_leadership_evidence_counts_even_when_worded_differently() -> None:
    """The posting says 'mentor', the CV says 'led'. Both are leadership."""
    result = score_job(job(description="Mentor and coach the team."), candidate())
    leadership = next(d for d in result.dimensions if d.name == "leadership_domain")
    assert leadership.score >= 0.6


def test_no_leadership_evidence_is_a_gap_not_a_zero() -> None:
    result = score_job(
        job(description="Lead the platform team and mentor engineers."),
        candidate(roles={"r1": "Backend Engineer at Acme"}, achievements={}, headline="Engineer"),
    )
    leadership = next(d for d in result.dimensions if d.name == "leadership_domain")
    assert leadership.score == pytest.approx(0.2)
    assert any(
        item.dimension == "leadership_domain" for item in result.requirements(EvidenceKind.GAP)
    )


def test_a_role_with_no_leadership_ask_is_scored_neutrally() -> None:
    result = score_job(job(description="Write backend code.", responsibilities=[]), candidate())
    leadership = next(d for d in result.dimensions if d.name == "leadership_domain")
    assert leadership.score == 0.5


def test_unstated_sponsorship_is_surfaced_as_uncertain() -> None:
    result = score_job(
        job(visa_sponsorship=None), candidate(), SearchPreferences(requires_sponsorship=True)
    )
    uncertain = result.requirements(EvidenceKind.UNCERTAIN)
    assert any("sponsor" in item.requirement for item in uncertain)


def test_a_target_title_lifts_role_fit_past_token_overlap() -> None:
    """'Head of Platform' and 'Engineering Lead' share no words but are the
    same target."""
    result = score_job(
        job(title="Head of Platform", normalized_title="head of platform"),
        candidate(),
        SearchPreferences(desired_titles=["Head of Platform"]),
    )
    role_fit = next(d for d in result.dimensions if d.name == "role_fit")
    assert role_fit.score >= 0.54


# --- the semantic signal ----------------------------------------------------


def test_the_embedding_signal_is_bounded() -> None:
    """It can move role fit, but it cannot decide the score."""
    without = score_job(job(), candidate())
    high = score_job(job(), candidate(), semantic_similarity=1.0)
    low = score_job(job(), candidate(), semantic_similarity=0.0)
    assert low.score < without.score <= high.score
    assert high.score - low.score <= 100 * WEIGHTS["role_fit"] + 0.01


def test_a_missing_embedding_leaves_the_score_untouched() -> None:
    assert (
        score_job(job(), candidate(), semantic_similarity=None).score
        == score_job(job(), candidate()).score
    )
