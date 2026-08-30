"""Evidence attached to a score.

Plan Phase 3 acceptance: every matched or missing requirement includes evidence.
A score without it is an opinion; with it, the user can check the reasoning and
disagree with a specific line.
"""

from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum


class EvidenceKind(StrEnum):
    MATCHED_REQUIREMENT = "matched_requirement"
    MISSING_REQUIREMENT = "missing_requirement"
    #: Something the posting asks for that we cannot confirm either way.
    UNCERTAIN = "uncertain"
    HARD_BLOCKER = "hard_blocker"
    STRENGTH = "strength"
    GAP = "gap"


@dataclass(frozen=True, slots=True)
class Evidence:
    """One justified claim about the fit.

    ``reference`` points at what supports it: ``fact:<uuid>`` for something the
    candidate has, ``job:<uuid>#field`` for something the posting says.
    """

    kind: EvidenceKind
    dimension: str
    requirement: str
    reference: str | None = None
    detail: str | None = None
    #: 'cv' when the claim rests on a candidate fact, 'job' when on the posting.
    source: str = "job"

    def as_dict(self) -> dict[str, str | None]:
        return {
            "kind": self.kind.value,
            "dimension": self.dimension,
            "requirement": self.requirement,
            "reference": self.reference,
            "detail": self.detail,
            "source": self.source,
        }
