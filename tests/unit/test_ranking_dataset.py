"""Rank-order assertions over a fixed dataset.

Phase 3 acceptance. This is the file that turns "the scorer feels right" into
something that fails when it stops being right. A change that reorders these
jobs is a deliberate decision, made here.
"""

from __future__ import annotations

import json
import pathlib

import pytest
from job_agent_connectors.normalize import normalize_title
from job_agent_domain.enums import MatchRouting
from job_agent_matching.preferences import SearchPreferences
from job_agent_matching.scoring import score_job
from job_agent_matching.types import CandidateView, JobView

DATASET = json.loads(
    (
        pathlib.Path(__file__).resolve().parents[2] / "fixtures" / "jobs" / "ranking_dataset.json"
    ).read_text()
)


def view(raw: dict[str, object]) -> JobView:
    """Normalise the title the way discovery does, so this fixture exercises the
    real path rather than a hand-written shortcut."""
    return JobView.model_validate(raw | {"normalized_title": normalize_title(str(raw["title"]))})


@pytest.fixture(scope="module")
def scored() -> dict[str, object]:
    candidate = CandidateView.model_validate(DATASET["candidate"])
    preferences = SearchPreferences.model_validate(DATASET["preferences"])
    return {job["id"]: score_job(view(job), candidate, preferences) for job in DATASET["jobs"]}


def test_the_expected_order_holds(scored) -> None:  # type: ignore[no-untyped-def]
    accepted = {
        job_id: result for job_id, result in scored.items() if job_id in DATASET["expected_order"]
    }
    ranked = sorted(accepted, key=lambda job_id: -accepted[job_id].score)
    assert ranked == DATASET["expected_order"], {
        job_id: accepted[job_id].score for job_id in ranked
    }


def test_scores_are_strictly_ordered_not_merely_sorted(scored) -> None:  # type: ignore[no-untyped-def]
    """Ties would make the order above accidental."""
    scores = [scored[job_id].score for job_id in DATASET["expected_order"]]
    assert scores == sorted(scores, reverse=True)
    assert len(set(scores)) == len(scores)


def test_the_best_match_is_high_priority(scored) -> None:  # type: ignore[no-untyped-def]
    best = scored[DATASET["expected_order"][0]]
    assert best.routing is MatchRouting.HIGH_PRIORITY
    assert best.score >= 80


def test_an_exact_seniority_match_outranks_a_step_up(scored) -> None:  # type: ignore[no-untyped-def]
    """Both are good roles. The one the candidate is already the right level for
    ranks first, and that is the intended behaviour, not an accident."""
    exact = scored["rank-2-senior-backend-amman"]
    step_up = scored["rank-1-lead-remote"]
    assert exact.score > step_up.score
    assert step_up.routing is MatchRouting.HIGH_PRIORITY


def test_roles_the_candidate_would_never_take_are_archived(scored) -> None:  # type: ignore[no-untyped-def]
    for job_id in DATASET["expected_archived"]:
        assert scored[job_id].routing is MatchRouting.ARCHIVED, job_id
        assert scored[job_id].score < 60, job_id


def test_every_job_in_the_dataset_is_accounted_for(scored) -> None:  # type: ignore[no-untyped-def]
    """A job added to the fixture without an expectation would otherwise be
    silently unasserted."""
    expected = (
        set(DATASET["expected_order"])
        | set(DATASET["expected_archived"])
        | set(DATASET["expected_rejected"])
    )
    assert set(scored) == expected


def test_blocked_jobs_are_rejected_for_the_stated_reason(scored) -> None:  # type: ignore[no-untyped-def]
    for job_id, rule in DATASET["expected_rejected"].items():
        result = scored[job_id]
        assert result.routing is MatchRouting.REJECTED, job_id
        assert rule in {blocker.rule for blocker in result.hard_blockers}, job_id


def test_a_rejected_job_still_reports_what_it_would_have_scored(scored) -> None:  # type: ignore[no-untyped-def]
    """An excluded company that would otherwise have been an excellent match is
    worth seeing as exactly that."""
    blocked = scored["blocked-excluded-company"]
    assert blocked.score > 70


def test_the_whole_dataset_is_reproducible() -> None:
    candidate = CandidateView.model_validate(DATASET["candidate"])
    preferences = SearchPreferences.model_validate(DATASET["preferences"])
    first = [score_job(view(job), candidate, preferences) for job in DATASET["jobs"]]
    second = [score_job(view(job), candidate, preferences) for job in DATASET["jobs"]]
    assert [r.score for r in first] == [r.score for r in second]
    assert [r.inputs_hash for r in first] == [r.inputs_hash for r in second]
