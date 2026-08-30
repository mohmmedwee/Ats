"""Contract tests for the ATS adapters.

These pin the mapping from each board's documented schema to our normalised
shape. When a board changes its response, the fixture is updated and these fail
until the adapter is updated with it.
"""

from __future__ import annotations

from datetime import UTC, datetime

import httpx
import pytest
from job_agent_connectors import (
    AshbySource,
    GreenhouseSource,
    LeverSource,
    SourceClient,
    SourceConfigError,
    build_source,
)
from job_agent_domain.enums import RemoteType, Seniority


def client_for(mock_client, payload, requests=None) -> SourceClient:  # type: ignore[no-untyped-def]
    return SourceClient(client=mock_client(payload, requests=requests), rate_limit_per_minute=6000)


# --- Greenhouse -------------------------------------------------------------


async def test_greenhouse_discovers_and_normalizes(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    requests: list[httpx.Request] = []
    source = GreenhouseSource(
        {"board_token": "northwind", "company": "Northwind Systems"},
        client=client_for(mock_client, recorded("greenhouse_board.json"), requests),
    )

    batch = await source.discover(None)
    assert len(batch.jobs) == 2
    assert requests[0].url.params["content"] == "true"
    assert "boards-api.greenhouse.io/v1/boards/northwind/jobs" in str(requests[0].url)

    job = source.normalize(batch.jobs[0])
    assert job.external_id == "4001234"
    assert job.company == "Northwind Systems"
    assert job.title == "Senior Backend Engineer (Python)"
    assert job.seniority is Seniority.SENIOR
    assert job.location == "Amman, Jordan"
    assert job.city == "Amman"
    assert job.country == "Jordan"
    assert job.visa_sponsorship is True
    assert job.posted_at == datetime(2026, 8, 20, 13, 15, tzinfo=UTC)


async def test_greenhouse_unescapes_html_content(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    """The board API returns the description as escaped HTML."""
    source = GreenhouseSource(
        {"board_token": "northwind"},
        client=client_for(mock_client, recorded("greenhouse_board.json")),
    )
    job = source.normalize((await source.discover(None)).jobs[0])
    assert "&lt;" not in job.description
    assert "<p>" not in job.description
    assert "We are building a multi-tenant platform." in job.description


async def test_greenhouse_splits_requirements_from_perks(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    source = GreenhouseSource(
        {"board_token": "northwind"},
        client=client_for(mock_client, recorded("greenhouse_board.json")),
    )
    job = source.normalize((await source.discover(None)).jobs[0])

    assert "5+ years with Python and FastAPI" in job.required_skills
    assert "Kubernetes" in job.preferred_skills
    assert "Design and ship backend services" in job.responsibilities
    # A benefits list is not a requirement.
    assert "Health cover" not in job.required_skills
    assert "Health cover" not in job.preferred_skills


async def test_greenhouse_strips_tracking_parameters(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    source = GreenhouseSource(
        {"board_token": "northwind"},
        client=client_for(mock_client, recorded("greenhouse_board.json")),
    )
    job = source.normalize((await source.discover(None)).jobs[0])
    assert job.canonical_url == "https://boards.greenhouse.io/northwind/jobs/4001234"
    assert "gh_src" in job.application_url


async def test_greenhouse_reads_a_refusal_to_sponsor(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    source = GreenhouseSource(
        {"board_token": "northwind"},
        client=client_for(mock_client, recorded("greenhouse_board.json")),
    )
    job = source.normalize((await source.discover(None)).jobs[1])
    assert job.visa_sponsorship is False
    assert job.remote_type is RemoteType.REMOTE


# --- Lever ------------------------------------------------------------------


async def test_lever_discovers_and_normalizes(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    requests: list[httpx.Request] = []
    source = LeverSource(
        {"site": "cedar", "company": "Cedar Analytics"},
        client=client_for(mock_client, recorded("lever_postings.json"), requests),
    )

    batch = await source.discover(None)
    assert len(batch.jobs) == 2
    assert requests[0].url.params["mode"] == "json"

    job = source.normalize(batch.jobs[0])
    assert job.title == "Staff Software Engineer, Payments"
    assert job.seniority is Seniority.STAFF
    assert job.employment_type == "full_time"
    assert job.remote_type is RemoteType.HYBRID
    assert job.city == "Dubai"
    assert job.country == "United Arab Emirates"


async def test_lever_folds_titled_lists_into_the_description(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    """Lever keeps requirements in separate list blocks, not the description."""
    source = LeverSource(
        {"site": "cedar"}, client=client_for(mock_client, recorded("lever_postings.json"))
    )
    job = source.normalize((await source.discover(None)).jobs[0])

    assert "8+ years building distributed systems" in job.required_skills
    assert "Payments domain experience" in job.preferred_skills
    assert "Own the ledger service" in job.responsibilities


async def test_lever_maps_contract_commitment(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    source = LeverSource(
        {"site": "cedar"}, client=client_for(mock_client, recorded("lever_postings.json"))
    )
    job = source.normalize((await source.discover(None)).jobs[1])
    assert job.employment_type == "contract"
    assert job.remote_type is RemoteType.REMOTE
    assert job.posted_at is not None


# --- Ashby ------------------------------------------------------------------


async def test_ashby_discovers_and_normalizes(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    requests: list[httpx.Request] = []
    source = AshbySource(
        {"job_board_name": "levantweb", "company": "Levant Web Works"},
        client=client_for(mock_client, recorded("ashby_board.json"), requests),
    )

    batch = await source.discover(None)
    assert len(batch.jobs) == 2
    assert requests[0].url.params["includeCompensation"] == "true"

    job = source.normalize(batch.jobs[0])
    assert job.title == "Lead Platform Engineer"
    assert job.seniority is Seniority.LEAD
    assert job.employment_type == "full_time"
    assert job.remote_type is RemoteType.ONSITE
    assert job.compensation is not None
    assert job.compensation["compensationTierSummary"] == "12,000 - 15,000 JOD"


async def test_ashby_trusts_its_explicit_remote_flag(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    source = AshbySource(
        {"job_board_name": "levantweb"},
        client=client_for(mock_client, recorded("ashby_board.json")),
    )
    job = source.normalize((await source.discover(None)).jobs[1])
    assert job.remote_type is RemoteType.REMOTE
    assert job.compensation is None


async def test_ashby_fetch_details_finds_one_posting(recorded, mock_client) -> None:  # type: ignore[no-untyped-def]
    source = AshbySource(
        {"job_board_name": "levantweb"},
        client=client_for(mock_client, recorded("ashby_board.json")),
    )
    raw = await source.fetch_details("e4b0f5d7-4444-4d5e-af60-3c4d5e6f7081")
    assert raw.payload["title"] == "Senior Data Engineer"


# --- shared behaviour -------------------------------------------------------


@pytest.mark.parametrize(
    ("kind", "config"),
    [
        ("greenhouse", {"board_token": "northwind"}),
        ("lever", {"site": "cedar"}),
        ("ashby", {"job_board_name": "levantweb"}),
    ],
)
def test_every_kind_is_buildable_from_the_registry(kind: str, config: dict[str, str]) -> None:
    source = build_source(kind, config)
    assert source.kind == kind


@pytest.mark.parametrize(
    ("kind", "config"),
    [("greenhouse", {}), ("lever", {}), ("ashby", {})],
)
def test_missing_configuration_is_rejected_at_build_time(kind: str, config: dict[str, str]) -> None:
    with pytest.raises(SourceConfigError):
        build_source(kind, config)


async def test_an_unexpected_response_shape_is_an_error(mock_client) -> None:  # type: ignore[no-untyped-def]
    from job_agent_connectors import SourceFetchError

    source = GreenhouseSource(
        {"board_token": "northwind"}, client=client_for(mock_client, {"unexpected": True})
    )
    with pytest.raises(SourceFetchError):
        await source.discover(None)


@pytest.mark.parametrize(
    ("kind", "config", "fixture"),
    [
        ("greenhouse", {"board_token": "northwind"}, "greenhouse_board.json"),
        ("lever", {"site": "cedar"}, "lever_postings.json"),
        ("ashby", {"job_board_name": "levantweb"}, "ashby_board.json"),
    ],
)
async def test_normalization_is_deterministic(  # type: ignore[no-untyped-def]
    kind: str, config: dict[str, str], fixture: str, recorded, mock_client
) -> None:
    """Phase 3 needs a reproducible score, which needs a reproducible input."""
    payload = recorded(fixture)
    first = build_source(kind, config, client=client_for(mock_client, payload))
    second = build_source(kind, config, client=client_for(mock_client, payload))

    left = [first.normalize(job) for job in (await first.discover(None)).jobs]
    right = [second.normalize(job) for job in (await second.discover(None)).jobs]

    assert [job.model_dump(exclude={"posted_at"}) for job in left] == [
        job.model_dump(exclude={"posted_at"}) for job in right
    ]
