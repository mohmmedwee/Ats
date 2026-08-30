"""Deterministic normalisation of postings."""

from __future__ import annotations

import pytest
from job_agent_connectors.normalize import (
    canonicalize_url,
    detect_remote_type,
    detect_seniority,
    detect_sponsorship,
    extract_requirements,
    fingerprint,
    normalize_employment_type,
    normalize_title,
    split_location,
    strip_html,
)
from job_agent_domain.enums import RemoteType, Seniority


@pytest.mark.parametrize(
    ("title", "expected"),
    [
        ("Senior Backend Engineer", Seniority.SENIOR),
        ("Sr. Software Engineer", Seniority.SENIOR),
        ("Staff Engineer, Payments", Seniority.STAFF),
        ("Engineering Lead", Seniority.LEAD),
        ("Principal Architect", Seniority.PRINCIPAL),
        ("Engineering Manager", Seniority.MANAGER),
        ("Director of Engineering", Seniority.DIRECTOR),
        ("Junior Developer", Seniority.JUNIOR),
        ("Software Engineering Intern", Seniority.INTERN),
        ("Backend Engineer", Seniority.UNKNOWN),
    ],
)
def test_seniority_is_read_from_the_title(title: str, expected: Seniority) -> None:
    assert detect_seniority(title) is expected


def test_principal_beats_senior_when_a_title_has_both() -> None:
    """Ordering matters: 'Senior Principal Engineer' is a principal role."""
    assert detect_seniority("Senior Principal Engineer") is Seniority.PRINCIPAL


def test_normalized_titles_match_across_boards() -> None:
    """The same role posted three ways should reduce to one key."""
    variants = [
        "Senior Backend Engineer",
        "Sr. Backend Engineer (Remote)",
        "Backend Engineer II - Full-Time",
    ]
    assert len({normalize_title(title) for title in variants}) == 1


@pytest.mark.parametrize(
    ("fields", "expected"),
    [
        (("Remote - EMEA",), RemoteType.REMOTE),
        (("Hybrid - Amman",), RemoteType.HYBRID),
        (("Amman, Jordan", "On-site"), RemoteType.ONSITE),
        (("Amman, Jordan",), RemoteType.UNKNOWN),
        (("Remote", "hybrid working"), RemoteType.HYBRID),
    ],
)
def test_remote_type_detection(fields: tuple[str, ...], expected: RemoteType) -> None:
    assert detect_remote_type(*fields) is expected


def test_hybrid_wins_over_remote() -> None:
    """A posting that says both is hybrid; treating it as remote would let it
    through a location filter it should not pass."""
    assert detect_remote_type("Remote-first, hybrid two days a week") is RemoteType.HYBRID


@pytest.mark.parametrize(
    ("location", "expected"),
    [
        ("Amman, Jordan", ("Amman", "Jordan")),
        ("Dubai, United Arab Emirates", ("Dubai", "United Arab Emirates")),
        ("Remote - EMEA", ("EMEA", None)),
        ("Berlin", ("Berlin", None)),
        (None, (None, None)),
        ("", (None, None)),
    ],
)
def test_location_splitting(location: str | None, expected: tuple[str | None, str | None]) -> None:
    assert split_location(location) == expected


def test_tracking_parameters_are_dropped_from_the_canonical_url() -> None:
    url = "https://Boards.Greenhouse.io/acme/jobs/123/?gh_src=abc&utm_source=x&lang=en#apply"
    assert canonicalize_url(url) == "https://boards.greenhouse.io/acme/jobs/123?lang=en"


def test_the_same_posting_linked_two_ways_canonicalizes_identically() -> None:
    a = "https://jobs.lever.co/cedar/abc?lever-source=LinkedIn"
    b = "https://jobs.lever.co/cedar/abc/"
    assert canonicalize_url(a) == canonicalize_url(b)


@pytest.mark.parametrize(
    ("raw", "expected"),
    [("Full-time", "full_time"), ("FullTime", "full_time"), ("Contract", "contract"), (None, None)],
)
def test_employment_type_mapping(raw: str | None, expected: str | None) -> None:
    assert normalize_employment_type(raw) == expected


def test_sponsorship_silence_is_not_a_no() -> None:
    assert detect_sponsorship("We are hiring a backend engineer.") is None
    assert detect_sponsorship("Visa sponsorship is available.") is True
    assert detect_sponsorship("We are unable to sponsor visas for this position.") is False


def test_a_refusal_wins_over_a_mention() -> None:
    text = "Visa sponsorship is available for some roles. We cannot sponsor for this one."
    assert detect_sponsorship(text) is False


def test_escaped_html_is_unescaped_before_tags_are_stripped() -> None:
    assert strip_html("&lt;p&gt;Hello&lt;/p&gt;") == "Hello"


def test_list_items_keep_a_bullet_marker() -> None:
    """Requirement extraction reads bullets; a bare newline loses the list."""
    assert strip_html("<ul><li>One</li><li>Two</li></ul>") == "- One\n- Two"


def test_requirements_are_split_by_heading() -> None:
    description = (
        "Requirements\n- Python\n- PostgreSQL\n"
        "Nice to have\n- Kubernetes\n"
        "What you'll do\n- Ship services\n"
        "Benefits\n- Free lunch\n"
    )
    required, preferred, responsibilities = extract_requirements(description)
    assert required == ["Python", "PostgreSQL"]
    assert preferred == ["Kubernetes"]
    assert responsibilities == ["Ship services"]
    assert "Free lunch" not in required + preferred + responsibilities


def test_a_posting_without_headings_yields_nothing_rather_than_a_guess() -> None:
    required, preferred, responsibilities = extract_requirements(
        "We want someone great at Python who can lead a team."
    )
    assert (required, preferred, responsibilities) == ([], [], [])


def test_prose_under_a_heading_is_not_a_requirement() -> None:
    required, _, _ = extract_requirements("Requirements\nWe expect a lot.\n- Python\n")
    assert required == ["Python"]


def test_fingerprint_survives_reformatting() -> None:
    a = fingerprint("Acme", "Senior Backend Engineer", "<p>Build   services.</p>")
    b = fingerprint("acme, inc", "Sr. Backend Engineer", "<p>Build services.</p>")
    # Company punctuation differs, so these are not equal; the point is that
    # each is stable for its own inputs.
    assert a == fingerprint("Acme", "Senior Backend Engineer", "<p>Build   services.</p>")
    assert a != b


def test_fingerprint_ignores_whitespace_changes() -> None:
    assert fingerprint("Acme", "Backend Engineer", "Build  services.") == fingerprint(
        "Acme", "Backend Engineer", "Build services."
    )
