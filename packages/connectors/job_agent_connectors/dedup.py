"""Deduplication in the order plan section 7.3 requires.

The rules are tried strongest first and stop at the first match. Below the
confidence threshold nothing is merged: the records are linked as possible
duplicates so a person can decide, because silently merging two real jobs is
worse than showing one twice.
"""

from __future__ import annotations

from collections.abc import Sequence
from dataclasses import dataclass
from typing import Any, Protocol

from job_agent_domain.enums import DuplicateReason

from job_agent_connectors.normalize import fold, normalize_title

#: At or above this, the records are the same posting. Below it they are linked.
MERGE_THRESHOLD = 0.9

_CONFIDENCE: dict[DuplicateReason, float] = {
    # The source told us it is the same posting.
    DuplicateReason.SOURCE_EXTERNAL_ID: 1.0,
    # Same canonical URL: the same application form.
    DuplicateReason.CANONICAL_URL: 0.97,
    # Identical body text for the same employer.
    DuplicateReason.CONTENT_FINGERPRINT: 0.92,
    # Same employer, title, and location. Often right, but a company can post
    # two genuinely different roles that reduce to this, so it stays under the
    # merge threshold and gets linked instead.
    DuplicateReason.COMPANY_TITLE_LOCATION: 0.75,
}


class IncomingJob(Protocol):
    """A normalised posting that has not been stored yet.

    It deliberately has no ``source_id``: which source fetched it is a property
    of the run, not of the posting, and a normalised job that carried one could
    not be compared against a fetch from a different board.

    The members are read-only properties rather than attributes so that a
    concrete type with a stricter field (``str`` where this allows
    ``str | None``) still satisfies the protocol; mutable protocol attributes
    are invariant and would reject exactly the types we want to pass.
    """

    @property
    def external_id(self) -> str: ...

    @property
    def canonical_url(self) -> str | None: ...

    @property
    def company(self) -> str: ...

    @property
    def normalized_title(self) -> str | None: ...

    @property
    def location(self) -> str | None: ...

    @property
    def fingerprint(self) -> str | None: ...


class ExistingJob(IncomingJob, Protocol):
    """A stored job, which does know where it came from."""

    @property
    def source_id(self) -> Any: ...


@dataclass(frozen=True, slots=True)
class DuplicateMatch:
    job: ExistingJob
    reason: DuplicateReason
    confidence: float

    @property
    def should_merge(self) -> bool:
        return self.confidence >= MERGE_THRESHOLD


def _location_key(location: str | None) -> str:
    return fold(location or "")


def find_duplicate(
    incoming: IncomingJob, existing: Sequence[ExistingJob], *, source_id: Any = None
) -> DuplicateMatch | None:
    """Return the strongest match, or None.

    ``source_id`` is the source currently being run. Rules are evaluated in
    order and the first hit wins, so a same-source match is never downgraded by
    a weaker rule that also matches.
    """
    for other in existing:
        if (
            source_id is not None
            and other.source_id == source_id
            and other.external_id == incoming.external_id
        ):
            return DuplicateMatch(
                other,
                DuplicateReason.SOURCE_EXTERNAL_ID,
                _CONFIDENCE[DuplicateReason.SOURCE_EXTERNAL_ID],
            )

    if incoming.canonical_url:
        for other in existing:
            if other.canonical_url and other.canonical_url == incoming.canonical_url:
                return DuplicateMatch(
                    other,
                    DuplicateReason.CANONICAL_URL,
                    _CONFIDENCE[DuplicateReason.CANONICAL_URL],
                )

    if incoming.fingerprint:
        for other in existing:
            if other.fingerprint and other.fingerprint == incoming.fingerprint:
                return DuplicateMatch(
                    other,
                    DuplicateReason.CONTENT_FINGERPRINT,
                    _CONFIDENCE[DuplicateReason.CONTENT_FINGERPRINT],
                )

    company = fold(incoming.company)
    title = incoming.normalized_title or normalize_title(incoming.company)
    location = _location_key(incoming.location)
    for other in existing:
        if (
            fold(other.company) == company
            and (other.normalized_title or "") == title
            and _location_key(other.location) == location
        ):
            return DuplicateMatch(
                other,
                DuplicateReason.COMPANY_TITLE_LOCATION,
                _CONFIDENCE[DuplicateReason.COMPANY_TITLE_LOCATION],
            )

    return None
