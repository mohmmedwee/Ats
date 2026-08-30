"""Sources, discovery runs, and job browsing over HTTP."""

from __future__ import annotations

import json
import pathlib
from collections.abc import AsyncIterator
from typing import Any

import httpx
import pytest
from job_agent_domain.db import get_sessionmaker
from job_agent_domain.models import Job, JobSource

pytestmark = pytest.mark.integration

FIXTURES = pathlib.Path(__file__).resolve().parents[2] / "fixtures" / "jobs"


@pytest.fixture
async def api(clean_db: None) -> AsyncIterator[httpx.AsyncClient]:
    from job_agent_api.main import create_app

    transport = httpx.ASGITransport(app=create_app())
    async with httpx.AsyncClient(
        transport=transport, base_url="http://test", headers={"Idempotency-Key": "test-key"}
    ) as client:
        yield client


async def test_source_kinds_are_advertised_with_their_configuration(api) -> None:  # type: ignore[no-untyped-def]
    body = (await api.get("/api/v1/sources/kinds")).json()
    assert set(body["supported"]) == {"ashby", "greenhouse", "lever"}
    greenhouse = next(k for k in body["kinds"] if k["kind"] == "greenhouse")
    assert greenhouse["required_config"] == ["board_token"]


async def test_a_source_can_be_created_and_listed(api) -> None:  # type: ignore[no-untyped-def]
    response = await api.post(
        "/api/v1/sources",
        json={"kind": "greenhouse", "name": "northwind", "config": {"board_token": "northwind"}},
    )
    assert response.status_code == 201
    assert response.json()["enabled"] is True
    # Auto-submit is off, and this endpoint cannot turn it on.
    assert response.json()["auto_submit_allowed"] is False

    listed = (await api.get("/api/v1/sources")).json()
    assert [source["name"] for source in listed] == ["northwind"]


async def test_a_misconfigured_source_is_rejected_at_creation(api) -> None:  # type: ignore[no-untyped-def]
    """Better to fail here than silently on the next scheduled run."""
    response = await api.post(
        "/api/v1/sources", json={"kind": "greenhouse", "name": "broken", "config": {}}
    )
    assert response.status_code == 400
    assert "board_token" in response.json()["detail"]


async def test_an_unknown_source_kind_is_rejected(api) -> None:  # type: ignore[no-untyped-def]
    response = await api.post(
        "/api/v1/sources", json={"kind": "linkedin", "name": "x", "config": {}}
    )
    assert response.status_code == 400
    assert "unknown source kind" in response.json()["detail"]


async def test_duplicate_source_names_are_rejected(api) -> None:  # type: ignore[no-untyped-def]
    payload = {"kind": "lever", "name": "cedar", "config": {"site": "cedar"}}
    assert (await api.post("/api/v1/sources", json=payload)).status_code == 201
    assert (await api.post("/api/v1/sources", json=payload)).status_code == 409


async def test_a_source_can_be_paused_and_its_failures_reset(api) -> None:  # type: ignore[no-untyped-def]
    created = (
        await api.post(
            "/api/v1/sources",
            json={"kind": "ashby", "name": "levantweb", "config": {"job_board_name": "levantweb"}},
        )
    ).json()

    async with get_sessionmaker()() as session:
        source = await session.get(JobSource, created["id"])
        assert source is not None
        source.consecutive_failures = 3
        source.last_error = "503"
        await session.commit()

    patched = await api.patch(
        f"/api/v1/sources/{created['id']}", json={"enabled": False, "reset_failures": True}
    )
    assert patched.status_code == 200
    assert patched.json()["enabled"] is False
    assert patched.json()["consecutive_failures"] == 0
    assert patched.json()["last_error"] is None


async def test_updating_a_source_validates_the_new_configuration(api) -> None:  # type: ignore[no-untyped-def]
    created = (
        await api.post(
            "/api/v1/sources",
            json={"kind": "lever", "name": "cedar", "config": {"site": "cedar"}},
        )
    ).json()
    response = await api.patch(f"/api/v1/sources/{created['id']}", json={"config": {}})
    assert response.status_code == 400


async def test_a_run_with_no_sources_is_an_empty_report(api) -> None:  # type: ignore[no-untyped-def]
    report = (await api.post("/api/v1/discovery/run")).json()
    assert report["results"] == []


async def test_jobs_can_be_filtered_and_paged(api) -> None:  # type: ignore[no-untyped-def]
    source = JobSource(kind="greenhouse", name="northwind", config={"board_token": "northwind"})
    async with get_sessionmaker()() as session:
        session.add(source)
        await session.flush()
        for index, (title, country, remote) in enumerate(
            [
                ("Senior Backend Engineer", "Jordan", "onsite"),
                ("Staff Platform Engineer", "Jordan", "remote"),
                ("Data Analyst", "Egypt", "remote"),
            ]
        ):
            session.add(
                Job(
                    source_id=source.id,
                    external_id=f"ext-{index}",
                    company="Northwind Systems",
                    title=title,
                    normalized_title=title.lower(),
                    description=f"{title} description",
                    application_url=f"https://boards.example.com/{index}",
                    canonical_url=f"https://boards.example.com/{index}",
                    content_hash=f"{index:064d}",
                    country=country,
                    remote_type=remote,
                )
            )
        await session.commit()

    everything = (await api.get("/api/v1/jobs")).json()
    assert everything["total"] == 3

    jordan = (await api.get("/api/v1/jobs", params={"country": "jordan"})).json()
    assert jordan["total"] == 2

    remote = (await api.get("/api/v1/jobs", params={"remote_type": "remote"})).json()
    assert remote["total"] == 2

    searched = (await api.get("/api/v1/jobs", params={"q": "platform"})).json()
    assert [item["title"] for item in searched["items"]] == ["Staff Platform Engineer"]

    paged = (await api.get("/api/v1/jobs", params={"limit": 1, "offset": 1})).json()
    assert len(paged["items"]) == 1
    assert paged["total"] == 3


async def test_linked_duplicates_are_hidden_by_default(api) -> None:  # type: ignore[no-untyped-def]
    source = JobSource(kind="greenhouse", name="northwind", config={"board_token": "northwind"})
    async with get_sessionmaker()() as session:
        session.add(source)
        await session.flush()
        original = Job(
            source_id=source.id,
            external_id="ext-1",
            company="Acme",
            title="Backend Engineer",
            description="d",
            application_url="https://example.com/1",
            content_hash="1" * 64,
        )
        session.add(original)
        await session.flush()
        session.add(
            Job(
                source_id=source.id,
                external_id="ext-2",
                company="Acme",
                title="Backend Engineer",
                description="d",
                application_url="https://example.com/2",
                content_hash="2" * 64,
                possible_duplicate_of=original.id,
                duplicate_reason="company_title_location",
                duplicate_confidence=0.75,
            )
        )
        await session.commit()

    assert (await api.get("/api/v1/jobs")).json()["total"] == 1
    with_dupes = (await api.get("/api/v1/jobs", params={"include_duplicates": True})).json()
    assert with_dupes["total"] == 2


async def test_a_job_detail_links_back_to_the_payload_it_came_from(api) -> None:  # type: ignore[no-untyped-def]
    """Phase 2 acceptance, over HTTP: every normalised field is traceable."""
    from datetime import UTC, datetime

    from job_agent_domain.models import JobRawSnapshot

    payload: dict[str, Any] = json.loads((FIXTURES / "greenhouse_board.json").read_text())
    item = payload["jobs"][0]

    async with get_sessionmaker()() as session:
        source = JobSource(kind="greenhouse", name="northwind", config={"board_token": "n"})
        session.add(source)
        await session.flush()
        job = Job(
            source_id=source.id,
            external_id=str(item["id"]),
            company="Northwind Systems",
            title=item["title"],
            description="d",
            application_url=item["absolute_url"],
            content_hash="a" * 64,
        )
        session.add(job)
        await session.flush()
        session.add(
            JobRawSnapshot(
                job_id=job.id,
                source_id=source.id,
                external_id=str(item["id"]),
                source_url=item["absolute_url"],
                fetched_at=datetime.now(UTC),
                content_hash="a" * 64,
                payload=item,
            )
        )
        await session.commit()
        job_id = job.id

    detail = (await api.get(f"/api/v1/jobs/{job_id}")).json()
    assert len(detail["snapshots"]) == 1

    snapshot_id = detail["snapshots"][0]["id"]
    raw = (await api.get(f"/api/v1/jobs/{job_id}/snapshots/{snapshot_id}")).json()
    assert raw["payload"]["title"] == item["title"]
    assert raw["source_url"] == item["absolute_url"]


async def test_a_missing_job_is_a_404(api) -> None:  # type: ignore[no-untyped-def]
    import uuid

    assert (await api.get(f"/api/v1/jobs/{uuid.uuid4()}")).status_code == 404


async def test_running_discovery_requires_an_idempotency_key(api) -> None:  # type: ignore[no-untyped-def]
    response = await api.post("/api/v1/discovery/run", headers={"Idempotency-Key": ""})
    assert response.status_code == 428
