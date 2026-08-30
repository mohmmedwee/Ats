"""The deterministic weighted scorer.

Plan section 7.4. Everything here is a pure function of the candidate view, the
job view, and the preferences: the same inputs always produce the same score,
which is what makes the Phase 3 reproducibility requirement testable and what
lets a user trust a ranking that changed.

The model is not involved. It writes an explanation afterwards, from this
result — it never sets the number.
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field

from job_agent_domain.enums import SENIORITY_RANK, MatchRouting, RemoteType

from job_agent_matching.compensation import ANNUALISE
from job_agent_matching.compensation import parse as parse_compensation
from job_agent_matching.evidence import Evidence, EvidenceKind
from job_agent_matching.filters import HardBlocker, apply_hard_filters
from job_agent_matching.preferences import SearchPreferences
from job_agent_matching.skills import (
    ARCHITECTURE_SKILLS,
    LEADERSHIP_TERMS,
    canonical,
    canonical_set,
)
from job_agent_matching.types import CandidateView, JobView

#: Plan section 7.4. Must sum to 1.0; asserted below so a future edit cannot
#: quietly change the scale of every score.
WEIGHTS: dict[str, float] = {
    "role_fit": 0.25,
    "required_skills": 0.25,
    "seniority": 0.15,
    "architecture_cloud": 0.15,
    "leadership_domain": 0.10,
    "location_auth_comp": 0.10,
}
assert abs(sum(WEIGHTS.values()) - 1.0) < 1e-9, "dimension weights must sum to 1.0"

#: Preferred skills count, but less than required ones.
PREFERRED_SKILL_WEIGHT = 0.4

#: How much of the role-fit dimension the embedding signal may move.
SEMANTIC_WEIGHT = 0.35

#: Plan section 7.4 routing.
HIGH_PRIORITY_SCORE = 80.0
NORMAL_REVIEW_SCORE = 70.0
POSSIBLE_MATCH_SCORE = 60.0

#: Version of the scoring logic. Part of the inputs hash, so a change to the
#: algorithm invalidates cached scores instead of mixing old and new.
SCORER_VERSION = "1"


@dataclass(slots=True)
class DimensionScore:
    name: str
    score: float
    weight: float
    evidence: list[Evidence] = field(default_factory=list)
    detail: str = ""

    @property
    def contribution(self) -> float:
        return self.score * self.weight * 100


@dataclass(slots=True)
class MatchResult:
    score: float
    routing: MatchRouting
    dimensions: list[DimensionScore]
    hard_blockers: list[HardBlocker]
    evidence: list[Evidence]
    inputs_hash: str

    @property
    def rejected(self) -> bool:
        return self.routing is MatchRouting.REJECTED

    def breakdown(self) -> dict[str, dict[str, float | str]]:
        return {
            dimension.name: {
                "score": round(dimension.score, 4),
                "weight": dimension.weight,
                "contribution": round(dimension.contribution, 2),
                "detail": dimension.detail,
            }
            for dimension in self.dimensions
        }

    def requirements(self, kind: EvidenceKind) -> list[Evidence]:
        return [item for item in self.evidence if item.kind is kind]


def _candidate_supports(candidate: CandidateView, skill: str) -> str | None:
    """Return the fact id backing a skill, or None.

    Falls back to the text of roles and achievements, because a CV often proves
    a skill in a sentence rather than in the skills list.
    """
    needle = canonical(skill)
    if not needle:
        return None
    if needle in candidate.skills:
        return candidate.skills[needle]
    for fact_id, text in {**candidate.roles, **candidate.achievements}.items():
        if needle in canonical(text):
            return fact_id
    return None


def _score_required_skills(job: JobView, candidate: CandidateView) -> DimensionScore:
    dimension = DimensionScore("required_skills", 0.0, WEIGHTS["required_skills"])
    required = [skill for skill in job.required_skills if canonical(skill)]
    preferred = [skill for skill in job.preferred_skills if canonical(skill)]

    if not required and not preferred:
        # A posting with no parseable requirements tells us nothing. Scoring it
        # zero would bury every job from a board that writes prose.
        dimension.score = 0.5
        dimension.detail = "posting lists no explicit requirements"
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.UNCERTAIN,
                dimension=dimension.name,
                requirement="requirements could not be read from this posting",
                reference=f"job:{job.id}#description",
            )
        )
        return dimension

    earned = 0.0
    possible = 0.0

    for skill in required:
        possible += 1.0
        fact_id = _candidate_supports(candidate, skill)
        if fact_id:
            earned += 1.0
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.MATCHED_REQUIREMENT,
                    dimension=dimension.name,
                    requirement=skill,
                    reference=f"fact:{fact_id}",
                    source="cv",
                )
            )
        else:
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.MISSING_REQUIREMENT,
                    dimension=dimension.name,
                    requirement=skill,
                    reference=f"job:{job.id}#required_skills",
                    detail="not found in your verified facts",
                )
            )

    for skill in preferred:
        possible += PREFERRED_SKILL_WEIGHT
        fact_id = _candidate_supports(candidate, skill)
        if fact_id:
            earned += PREFERRED_SKILL_WEIGHT
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.MATCHED_REQUIREMENT,
                    dimension=dimension.name,
                    requirement=f"{skill} (preferred)",
                    reference=f"fact:{fact_id}",
                    source="cv",
                )
            )
        else:
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.GAP,
                    dimension=dimension.name,
                    requirement=f"{skill} (preferred)",
                    reference=f"job:{job.id}#preferred_skills",
                )
            )

    dimension.score = earned / possible if possible else 0.0
    matched = sum(1 for item in dimension.evidence if item.kind is EvidenceKind.MATCHED_REQUIREMENT)
    dimension.detail = f"{matched} of {len(required) + len(preferred)} listed skills"
    return dimension


def _score_role_fit(
    job: JobView,
    candidate: CandidateView,
    preferences: SearchPreferences,
    semantic_similarity: float | None = None,
) -> DimensionScore:
    dimension = DimensionScore("role_fit", 0.0, WEIGHTS["role_fit"])

    title = canonical(job.normalized_title or job.title)
    title_tokens = {token for token in title.split() if len(token) > 2}

    candidate_text = " ".join(
        [canonical(candidate.headline or "")] + [canonical(v) for v in candidate.roles.values()]
    )
    candidate_tokens = {token for token in candidate_text.split() if len(token) > 2}

    overlap = title_tokens & candidate_tokens
    title_score = len(overlap) / len(title_tokens) if title_tokens else 0.0

    if overlap:
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.STRENGTH,
                dimension=dimension.name,
                requirement=f"title overlap: {', '.join(sorted(overlap))}",
                reference=f"job:{job.id}#title",
            )
        )

    # Checked against the raw title, not the normalised one: normalisation
    # strips seniority words, so a preference of "Engineering Lead" would never
    # match a normalised "engineering platform".
    raw_title = canonical(job.title)
    desired_hit = next(
        (
            want
            for want in preferences.desired_titles
            if canonical(want) and canonical(want) in raw_title
        ),
        None,
    )
    if desired_hit:
        title_score = max(title_score, 0.9)
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.STRENGTH,
                dimension=dimension.name,
                requirement=f"matches your target title {desired_hit!r}",
                reference=f"job:{job.id}#title",
            )
        )

    responsibility_hits = 0
    for responsibility in job.responsibilities:
        if any(
            token in candidate_tokens
            for token in canonical(responsibility).split()
            if len(token) > 4
        ):
            responsibility_hits += 1
    responsibility_score = (
        responsibility_hits / len(job.responsibilities) if job.responsibilities else title_score
    )

    lexical = 0.6 * title_score + 0.4 * responsibility_score
    dimension.detail = (
        f"{len(overlap)}/{len(title_tokens) or 0} title terms, "
        f"{responsibility_hits}/{len(job.responsibilities)} responsibilities"
    )

    if semantic_similarity is None:
        dimension.score = round(lexical, 4)
        return dimension

    # Embeddings catch a role described in words the CV does not reuse. They are
    # a minority of the dimension on purpose: a similarity number cannot be
    # shown to the user as a reason, and this score has to stay explainable.
    dimension.score = round(
        (1 - SEMANTIC_WEIGHT) * lexical + SEMANTIC_WEIGHT * semantic_similarity, 4
    )
    dimension.detail += f", semantic similarity {semantic_similarity:.2f}"
    dimension.evidence.append(
        Evidence(
            kind=EvidenceKind.STRENGTH if semantic_similarity >= 0.6 else EvidenceKind.GAP,
            dimension=dimension.name,
            requirement=f"overall similarity to your profile is {semantic_similarity:.0%}",
            reference=f"job:{job.id}#description",
        )
    )
    return dimension


def _score_seniority(job: JobView, candidate: CandidateView) -> DimensionScore:
    dimension = DimensionScore("seniority", 0.0, WEIGHTS["seniority"])

    job_rank = SENIORITY_RANK.get(job.seniority)
    candidate_rank = SENIORITY_RANK.get(candidate.seniority)

    if job_rank is None or candidate_rank is None:
        # Off-ladder (manager, director) or unknown: fall back to years, which
        # is weaker but not a guess dressed as a match.
        years = candidate.years_experience
        dimension.score = 0.5 if years is None else min(1.0, max(0.2, years / 10))
        dimension.detail = (
            "seniority not comparable; scored on years of experience"
            if years is not None
            else "seniority and experience unknown"
        )
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.UNCERTAIN,
                dimension=dimension.name,
                requirement=f"role seniority is {job.seniority.value}",
                reference=f"job:{job.id}#seniority",
            )
        )
        return dimension

    gap = abs(job_rank - candidate_rank)
    dimension.score = {0: 1.0, 1: 0.75, 2: 0.4}.get(gap, 0.15)
    if job_rank > candidate_rank:
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.GAP,
                dimension=dimension.name,
                requirement=f"role is {job.seniority.value}, you are {candidate.seniority.value}",
                reference=f"job:{job.id}#seniority",
                detail="a step up",
            )
        )
    elif job_rank < candidate_rank:
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.GAP,
                dimension=dimension.name,
                requirement=f"role is {job.seniority.value}, you are {candidate.seniority.value}",
                reference=f"job:{job.id}#seniority",
                detail="a step down",
            )
        )
    else:
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.STRENGTH,
                dimension=dimension.name,
                requirement=f"seniority matches at {job.seniority.value}",
                reference=f"job:{job.id}#seniority",
            )
        )
    dimension.detail = f"{job.seniority.value} vs {candidate.seniority.value}"
    return dimension


def _score_architecture(job: JobView, candidate: CandidateView) -> DimensionScore:
    dimension = DimensionScore("architecture_cloud", 0.0, WEIGHTS["architecture_cloud"])

    job_skills = canonical_set(job.required_skills + job.preferred_skills)
    described = canonical(job.description)
    wanted = {skill for skill in ARCHITECTURE_SKILLS if skill in job_skills or skill in described}

    if not wanted:
        dimension.score = 0.5
        dimension.detail = "posting names no architecture or cloud requirements"
        return dimension

    held: set[str] = set()
    for skill in sorted(wanted):
        fact_id = _candidate_supports(candidate, skill)
        if fact_id:
            held.add(skill)
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.MATCHED_REQUIREMENT,
                    dimension=dimension.name,
                    requirement=skill,
                    reference=f"fact:{fact_id}",
                    source="cv",
                )
            )
        else:
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.MISSING_REQUIREMENT,
                    dimension=dimension.name,
                    requirement=skill,
                    reference=f"job:{job.id}#description",
                )
            )

    dimension.score = len(held) / len(wanted)
    dimension.detail = f"{len(held)} of {len(wanted)} architecture and cloud areas"
    return dimension


def _score_leadership(job: JobView, candidate: CandidateView) -> DimensionScore:
    dimension = DimensionScore("leadership_domain", 0.0, WEIGHTS["leadership_domain"])

    posting = canonical(f"{job.title} {job.description} {' '.join(job.responsibilities)}")
    wanted = sorted(term for term in LEADERSHIP_TERMS if term in posting)

    if not wanted:
        dimension.score = 0.5
        dimension.detail = "role does not describe leadership responsibilities"
        return dimension

    evidence_text = {
        **candidate.roles,
        **candidate.achievements,
        "headline": candidate.headline or "",
    }

    # Leadership evidence counts as leadership evidence: a candidate who led a
    # team should not score badly because the posting happened to say "mentor"
    # and their CV says "led". Overlap on the specific terms raises the score,
    # but showing any leadership at all is what carries it.
    supporting: dict[str, str] = {}
    shown: set[str] = set()
    for fact_id, text in evidence_text.items():
        folded = canonical(text)
        found = {term for term in LEADERSHIP_TERMS if term in folded}
        if found:
            supporting[fact_id] = text
            shown |= found

    if supporting:
        for fact_id, text in list(supporting.items())[:3]:
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.STRENGTH,
                    dimension=dimension.name,
                    requirement="leadership experience",
                    reference=f"fact:{fact_id}" if fact_id != "headline" else None,
                    detail=text[:200],
                    source="cv",
                )
            )
        overlap = shown & set(wanted)
        dimension.score = round(0.6 + 0.4 * (len(overlap) / len(wanted)), 4)
    else:
        dimension.score = 0.2
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.GAP,
                dimension=dimension.name,
                requirement=f"role asks for {', '.join(wanted[:3])}",
                reference=f"job:{job.id}#description",
                detail="no leadership evidence in your verified facts",
            )
        )
    dimension.detail = f"{len(supporting)} supporting facts for {len(wanted)} leadership signals"

    return dimension


def _score_practicalities(
    job: JobView, candidate: CandidateView, preferences: SearchPreferences
) -> DimensionScore:
    """Location, authorisation, and pay — the things that decide whether a good
    match is actually takeable."""
    dimension = DimensionScore("location_auth_comp", 0.0, WEIGHTS["location_auth_comp"])
    parts: list[float] = []

    if job.remote_type is RemoteType.REMOTE:
        parts.append(1.0)
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.STRENGTH,
                dimension=dimension.name,
                requirement="fully remote",
                reference=f"job:{job.id}#remote_type",
            )
        )
    elif (
        job.country and candidate.country and canonical(job.country) == canonical(candidate.country)
    ):
        parts.append(1.0)
    elif preferences.willing_to_relocate:
        parts.append(0.6)
    elif job.country and candidate.country:
        parts.append(0.2)
    else:
        parts.append(0.5)

    if preferences.requires_sponsorship:
        if job.visa_sponsorship is True:
            parts.append(1.0)
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.STRENGTH,
                    dimension=dimension.name,
                    requirement="sponsorship available",
                    reference=f"job:{job.id}#visa_sponsorship",
                )
            )
        elif job.visa_sponsorship is None:
            parts.append(0.4)
            dimension.evidence.append(
                Evidence(
                    kind=EvidenceKind.UNCERTAIN,
                    dimension=dimension.name,
                    requirement="posting does not say whether it sponsors visas",
                    reference=f"job:{job.id}#description",
                    detail="worth asking before applying",
                )
            )
        else:
            parts.append(0.0)
    else:
        parts.append(1.0)

    floor = preferences.minimum_compensation
    stated = parse_compensation(job.compensation)
    if floor is None:
        parts.append(0.7)
    elif stated is None:
        parts.append(0.5)
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.UNCERTAIN,
                dimension=dimension.name,
                requirement="posting does not state compensation",
                reference=f"job:{job.id}#compensation",
            )
        )
    elif stated.currency.upper() != floor.currency.upper():
        parts.append(0.5)
        dimension.evidence.append(
            Evidence(
                kind=EvidenceKind.UNCERTAIN,
                dimension=dimension.name,
                requirement=(
                    f"pay is quoted in {stated.currency}, your minimum is in {floor.currency}"
                ),
                reference=f"job:{job.id}#compensation",
                detail="no conversion is applied",
            )
        )
    else:
        wanted = floor.amount * ANNUALISE.get(floor.period, 1.0)
        top = stated.annual_maximum() or 0.0
        parts.append(1.0 if top >= wanted else 0.3)

    dimension.score = sum(parts) / len(parts)
    dimension.detail = "location, authorisation, and pay"
    return dimension


def route(score: float, blockers: list[HardBlocker]) -> MatchRouting:
    if blockers:
        return MatchRouting.REJECTED
    if score >= HIGH_PRIORITY_SCORE:
        return MatchRouting.HIGH_PRIORITY
    if score >= NORMAL_REVIEW_SCORE:
        return MatchRouting.NORMAL_REVIEW
    if score >= POSSIBLE_MATCH_SCORE:
        return MatchRouting.POSSIBLE_MATCH
    return MatchRouting.ARCHIVED


def inputs_hash(
    job: JobView,
    candidate: CandidateView,
    preferences: SearchPreferences,
    *,
    embedding_model: str | None = None,
    semantic_similarity: float | None = None,
) -> str:
    """Identify the exact inputs a score was computed from.

    Covers the scorer version and the weights as well as the data, so changing
    the algorithm invalidates stored scores rather than leaving a mix of old and
    new numbers ranked against each other.
    """
    material = {
        "scorer": SCORER_VERSION,
        "weights": WEIGHTS,
        "job": {
            "id": job.id,
            "content_hash": job.content_hash,
            "title": job.title,
            "seniority": job.seniority.value,
            "required_skills": sorted(job.required_skills),
            "preferred_skills": sorted(job.preferred_skills),
            "country": job.country,
            "remote_type": job.remote_type.value,
            "visa_sponsorship": job.visa_sponsorship,
            "compensation": job.compensation,
        },
        "candidate": {
            "profile_id": candidate.profile_id,
            "version": candidate.profile_version,
            "seniority": candidate.seniority.value,
            "years": candidate.years_experience,
            "skills": sorted(candidate.skills),
            "roles": sorted(candidate.roles.values()),
        },
        "preferences": preferences.model_dump(mode="json"),
        # The embedding model is part of the inputs: the same text scored with a
        # different model is a different score, and must not reuse a cached one.
        "embedding_model": embedding_model,
        "semantic_similarity": (
            None if semantic_similarity is None else round(semantic_similarity, 6)
        ),
    }
    encoded = json.dumps(material, sort_keys=True, separators=(",", ":"), default=str)
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def score_job(
    job: JobView,
    candidate: CandidateView,
    preferences: SearchPreferences | None = None,
    *,
    semantic_similarity: float | None = None,
    embedding_model: str | None = None,
) -> MatchResult:
    """Score one job.

    Pure and deterministic: no I/O happens here. The embedding similarity, when
    used, is computed by the caller and passed in, so this function can be
    tested and reproduced without a model running.
    """
    preferences = preferences or SearchPreferences()
    blockers = apply_hard_filters(job, preferences)

    dimensions = [
        _score_role_fit(job, candidate, preferences, semantic_similarity),
        _score_required_skills(job, candidate),
        _score_seniority(job, candidate),
        _score_architecture(job, candidate),
        _score_leadership(job, candidate),
        _score_practicalities(job, candidate, preferences),
    ]

    total = round(sum(dimension.contribution for dimension in dimensions), 2)
    evidence = [item for dimension in dimensions for item in dimension.evidence]
    evidence.extend(
        Evidence(
            kind=EvidenceKind.HARD_BLOCKER,
            dimension="hard_filter",
            requirement=blocker.reason,
            reference=blocker.evidence,
            detail=blocker.rule,
        )
        for blocker in blockers
    )

    return MatchResult(
        # A rejected job keeps its score: the user can see it was an 88 that
        # failed on location, which is different from an 88 that failed on pay.
        score=total,
        routing=route(total, blockers),
        dimensions=dimensions,
        hard_blockers=blockers,
        evidence=evidence,
        inputs_hash=inputs_hash(
            job,
            candidate,
            preferences,
            embedding_model=embedding_model,
            semantic_similarity=semantic_similarity,
        ),
    )
