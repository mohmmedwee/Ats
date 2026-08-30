"""Deduplication order and the merge threshold (plan section 7.3)."""

from __future__ import annotations

from dataclasses import dataclass

from job_agent_connectors.dedup import MERGE_THRESHOLD, find_duplicate
from job_agent_domain.enums import DuplicateReason


@dataclass
class FakeJob:
    source_id: str = "source-a"
    external_id: str = "ext-1"
    canonical_url: str | None = "https://boards.example.com/acme/1"
    company: str = "Acme"
    normalized_title: str | None = "backend engineer"
    location: str | None = "Amman, Jordan"
    fingerprint: str | None = "f" * 64


def test_no_match_returns_none() -> None:
    assert find_duplicate(FakeJob(), [], source_id="source-a") is None


def test_same_source_and_external_id_is_certain() -> None:
    match = find_duplicate(FakeJob(), [FakeJob()], source_id="source-a")
    assert match is not None
    assert match.reason is DuplicateReason.SOURCE_EXTERNAL_ID
    assert match.confidence == 1.0
    assert match.should_merge


def test_a_run_without_a_source_id_skips_the_first_rule() -> None:
    """Normalised postings carry no source, so callers must pass the source id
    for the strongest rule to apply at all."""
    match = find_duplicate(FakeJob(), [FakeJob()])
    assert match is not None
    assert match.reason is DuplicateReason.CANONICAL_URL


def test_the_strongest_rule_wins_over_a_weaker_one_that_also_matches() -> None:
    """Rules are ordered; a same-source hit must not be reported as a weaker
    company/title/location match."""
    existing = [
        FakeJob(source_id="source-b", external_id="other", canonical_url=None, fingerprint=None),
        FakeJob(),
    ]
    match = find_duplicate(FakeJob(), existing, source_id="source-a")
    assert match is not None
    assert match.reason is DuplicateReason.SOURCE_EXTERNAL_ID


def test_the_same_url_from_two_sources_is_one_posting() -> None:
    incoming = FakeJob(source_id="source-b", external_id="ext-9", fingerprint=None)
    match = find_duplicate(incoming, [FakeJob()], source_id="source-b")
    assert match is not None
    assert match.reason is DuplicateReason.CANONICAL_URL
    assert match.should_merge


def test_identical_content_is_a_duplicate() -> None:
    incoming = FakeJob(
        source_id="source-b", external_id="ext-9", canonical_url="https://other.example.com/x"
    )
    match = find_duplicate(incoming, [FakeJob()], source_id="source-b")
    assert match is not None
    assert match.reason is DuplicateReason.CONTENT_FINGERPRINT
    assert match.should_merge


def test_company_title_and_location_is_linked_not_merged() -> None:
    """A company can genuinely post two different roles that reduce to the same
    company, title, and location, so this rule stays below the threshold."""
    incoming = FakeJob(
        source_id="source-b",
        external_id="ext-9",
        canonical_url="https://other.example.com/x",
        fingerprint="a" * 64,
    )
    match = find_duplicate(incoming, [FakeJob()], source_id="source-b")
    assert match is not None
    assert match.reason is DuplicateReason.COMPANY_TITLE_LOCATION
    assert not match.should_merge
    assert match.confidence < MERGE_THRESHOLD


def test_company_matching_ignores_punctuation_and_case() -> None:
    incoming = FakeJob(
        source_id="source-b",
        external_id="ext-9",
        canonical_url=None,
        fingerprint=None,
        company="acme, inc.",
    )
    existing = FakeJob(company="Acme Inc")
    match = find_duplicate(incoming, [existing], source_id="source-b")
    assert match is not None
    assert match.reason is DuplicateReason.COMPANY_TITLE_LOCATION


def test_a_different_location_is_a_different_job() -> None:
    incoming = FakeJob(
        source_id="source-b",
        external_id="ext-9",
        canonical_url=None,
        fingerprint=None,
        location="Dubai",
    )
    assert find_duplicate(incoming, [FakeJob()], source_id="source-b") is None
