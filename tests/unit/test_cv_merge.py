"""Reprocessing a CV must not undo the user's work."""

from __future__ import annotations

from job_agent_cv.merge import ExistingFact, merge_profile_fields, plan_merge
from job_agent_cv.parser import FactDraft
from job_agent_domain.enums import FactKind, FactProvenance


def draft(value: str, kind: FactKind = FactKind.SKILL) -> FactDraft:
    return FactDraft(kind=kind, value=value, provenance=FactProvenance.CV_DERIVED)


def existing(
    value: str,
    provenance: FactProvenance = FactProvenance.CV_DERIVED,
    kind: FactKind = FactKind.SKILL,
) -> ExistingFact:
    return ExistingFact(id=value, kind=str(kind), value=value, provenance=provenance)


def test_user_confirmed_facts_survive_a_reparse_that_drops_them() -> None:
    """Phase 1 acceptance: user edits survive reprocessing."""
    plan = plan_merge(
        existing=[existing("Kubernetes", FactProvenance.USER_CONFIRMED), existing("PHP")],
        drafts=[draft("Python")],
    )
    kept = {fact.value for fact in plan.kept}
    withdrawn = {fact.value for fact in plan.to_withdraw}

    assert "Kubernetes" in kept
    assert "PHP" in withdrawn
    assert [d.value for d in plan.to_insert] == ["Python"]


def test_facts_still_supported_by_the_new_parse_are_kept_not_reinserted() -> None:
    plan = plan_merge(existing=[existing("Python")], drafts=[draft("Python")])
    assert [fact.value for fact in plan.kept] == ["Python"]
    assert plan.to_insert == []
    assert plan.to_withdraw == []


def test_matching_is_case_insensitive() -> None:
    plan = plan_merge(existing=[existing("FastAPI")], drafts=[draft("fastapi")])
    assert plan.to_withdraw == []
    assert plan.to_insert == []


def test_same_value_under_a_different_kind_is_a_different_fact() -> None:
    plan = plan_merge(
        existing=[existing("Arabic", kind=FactKind.LANGUAGE)],
        drafts=[draft("Arabic", kind=FactKind.SKILL)],
    )
    assert [fact.value for fact in plan.to_withdraw] == ["Arabic"]
    assert [d.kind for d in plan.to_insert] == [FactKind.SKILL]


def test_an_unchanged_reparse_is_a_noop() -> None:
    plan = plan_merge(existing=[existing("Python")], drafts=[draft("Python")])
    assert plan.is_noop


def test_locked_profile_fields_are_not_overwritten() -> None:
    merged = merge_profile_fields(
        current={"headline": "Engineering Lead", "location": "Amman, Jordan"},
        parsed={"headline": "Backend Developer", "location": "Amman"},
        locked_fields=["headline"],
    )
    assert merged["headline"] == "Engineering Lead"
    assert merged["location"] == "Amman"


def test_a_parse_that_finds_nothing_does_not_erase_what_is_there() -> None:
    merged = merge_profile_fields(
        current={"location": "Amman, Jordan"}, parsed={"location": None}, locked_fields=[]
    )
    assert merged["location"] == "Amman, Jordan"
