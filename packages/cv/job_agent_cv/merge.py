"""Merging a fresh parse into an existing profile.

Phase 1 acceptance: user edits survive reprocessing. Reprocessing a CV is
therefore not a replace. It is a merge with two rules:

* A fact the user confirmed is never removed or rewritten by a parse.
* A profile field the user edited by hand is locked and left alone.

Everything else — facts derived from a previous parse that the new one no longer
supports — is withdrawn, because leaving it would mean the profile keeps a claim
the current CV does not make.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from job_agent_domain.enums import FactProvenance

from job_agent_cv.parser import FactDraft


@dataclass(frozen=True, slots=True)
class ExistingFact:
    """The subset of a stored fact that merging needs."""

    id: str
    kind: str
    value: str
    provenance: FactProvenance


@dataclass(slots=True)
class MergePlan:
    """What a reprocess would do. Computed before anything is written."""

    to_insert: list[FactDraft] = field(default_factory=list)
    to_withdraw: list[ExistingFact] = field(default_factory=list)
    kept: list[ExistingFact] = field(default_factory=list)

    @property
    def is_noop(self) -> bool:
        return not self.to_insert and not self.to_withdraw


def _key(kind: str, value: str) -> tuple[str, str]:
    return (str(kind), value.strip().casefold())


def plan_merge(existing: list[ExistingFact], drafts: list[FactDraft]) -> MergePlan:
    plan = MergePlan()
    incoming = {_key(str(draft.kind), draft.value): draft for draft in drafts}
    seen: set[tuple[str, str]] = set()

    for fact in existing:
        key = _key(fact.kind, fact.value)
        seen.add(key)
        if fact.provenance is FactProvenance.USER_CONFIRMED:
            # Confirmed by a person. A parse does not get to disagree.
            plan.kept.append(fact)
            continue
        if key in incoming:
            plan.kept.append(fact)
            continue
        plan.to_withdraw.append(fact)

    plan.to_insert = [draft for key, draft in incoming.items() if key not in seen]
    return plan


def merge_profile_fields(
    current: dict[str, object],
    parsed: dict[str, object],
    locked_fields: list[str],
) -> dict[str, object]:
    """Apply parsed values to profile fields, skipping anything the user locked.

    A field is also left alone when the parse has nothing for it: a re-parse that
    fails to find a location should not erase the one already there.
    """
    locked = set(locked_fields)
    merged = dict(current)
    for name, value in parsed.items():
        if name in locked or value is None:
            continue
        merged[name] = value
    return merged
