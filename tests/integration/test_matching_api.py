"""Scoring, the review queue, and shortlisting over HTTP."""

from __future__ import annotations

import json
from collections.abc import AsyncIterator
from datetime import UTC, datetime
from typing import Any

import httpx
import pytest
from job_agent_ai import Completion, FakeProvider
from job_agent_domain.db import get_sessionmaker
from job_agent_domain.enums import (
    ApplicationStatus,
    FactKind,
    FactProvenance,
    MatchRouting,
    RemoteType,
    Seniority,
)
from job_agent_domain.models import (
    Application,
    CandidateFact,
    CandidateProfile,
    Job,
    JobMatch,
    JobSource,
    MatchEvidence,
    User,
)
from sqlalchemy import func, select

pytestmark = pytest.mark.integration


@pytest.fixture
async def api(clean_db: None) -> AsyncIterator[tuple[httpx.AsyncClient, FakeProvider]]:
    from job_agent_api.dependencies import get_ai_provider
    from job_agent_api.main import create_app

    provider = FakeProvider()
    app = create_app()
    app.dependency_overrides[get_ai_provider] = lambda: provider
    transport = httpx.ASGITransport(app=app)
    async with httpx.AsyncClient(
        transport=transport, base_url="http://test", headers={"Idempotency-Key": "k"}
    ) as client:
        yield client, provider


async def seed(**job_overrides: Any) -> dict[str, Any]:
    """A profile with confirmed facts, and one job to score against it."""
    async with get_sessionmaker()() as session:
        user = User(email="owner@localhost", display_name="Local User")
        session.add(user)
        await session.flush()

        profile = CandidateProfile(
            user_id=user.id,
            headline="Engineering Lead / Senior Backend Engineer",
            location="Amman, Jordan",
            years_experience=7,
            preferences={"target_countries": ["Jordan"], "desired_titles": ["Backend Engineer"]},
        )
        session.add(profile)
        await session.flush()

        for kind, value in [
            (FactKind.SKILL, "Python"),
            (FactKind.SKILL, "FastAPI"),
            (FactKind.SKILL, "PostgreSQL"),
            (FactKind.SKILL, "Kubernetes"),
            (FactKind.ROLE, "Engineering Lead at Northwind Systems"),
            (FactKind.ACHIEVEMENT, "Led a team of six engineers"),
        ]:
            session.add(
                CandidateFact(
                    profile_id=profile.id,
                    kind=kind,
                    value=value,
                    provenance=FactProvenance.CV_DERIVED,
                )
            )

        source = JobSource(kind="greenhouse", name="northwind", config={"board_token": "n"})
        session.add(source)
        await session.flush()

        base: dict[str, Any] = {
            "source_id": source.id,
            "external_id": "ext-1",
            "company": "Northwind Systems",
            "title": "Senior Backend Engineer",
            "normalized_title": "backend engineer",
            "seniority": Seniority.SENIOR,
            "description": "Build services with Python and FastAPI. Mentor engineers.",
            "application_url": "https://boards.example.com/1",
            "canonical_url": "https://boards.example.com/1",
            "content_hash": "a" * 64,
            "country": "Jordan",
            "city": "Amman",
            "remote_type": RemoteType.REMOTE,
            "required_skills": ["Python", "FastAPI", "PostgreSQL"],
            "preferred_skills": ["Kubernetes"],
            "responsibilities": ["Design backend services"],
            "posted_at": datetime.now(UTC),
        }
        job = Job(**(base | job_overrides))
        session.add(job)
        await session.commit()
        return {"job_id": job.id, "profile_id": profile.id, "user_id": user.id}


def explanation_payload() -> str:
    return json.dumps(
        {
            "summary": "Strong match on backend skills.",
            "strengths": [{"text": "Python is confirmed", "evidence_index": 0}],
            "gaps": [],
            "red_flags": [],
            "questions_to_ask": ["What does the team own?"],
        }
    )


# --- scoring ----------------------------------------------------------------


async def test_scoring_a_job_returns_a_breakdown_with_evidence(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    seeded = await seed()

    response = await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={})
    assert response.status_code == 200
    body = response.json()

    assert body["score"] > 0
    assert set(body["breakdown"]) == {
        "role_fit",
        "required_skills",
        "seniority",
        "architecture_cloud",
        "leadership_domain",
        "location_auth_comp",
    }
    assert body["inputs_hash"]

    matched = [item for item in body["evidence"] if item["kind"] == "matched_requirement"]
    assert matched
    assert all(item["reference"] for item in matched)
    assert any(item["reference"].startswith("fact:") for item in matched)


async def test_rescoring_unchanged_inputs_reuses_the_stored_score(api) -> None:  # type: ignore[no-untyped-def]
    """Phase 3 acceptance: the score is reproducible for unchanged inputs."""
    client, _ = api
    seeded = await seed()

    first = (await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={})).json()
    second = (await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={})).json()

    assert first["score"] == second["score"]
    assert first["inputs_hash"] == second["inputs_hash"]
    assert first["match_id"] == second["match_id"]

    async with get_sessionmaker()() as session:
        assert await session.scalar(select(func.count()).select_from(JobMatch)) == 1


async def test_a_rescore_replaces_evidence_rather_than_accumulating_it(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    seeded = await seed()
    await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={})

    async with get_sessionmaker()() as session:
        before = await session.scalar(select(func.count()).select_from(MatchEvidence))

    await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={"force": True})

    async with get_sessionmaker()() as session:
        after = await session.scalar(select(func.count()).select_from(MatchEvidence))
    assert after == before


async def test_only_confirmed_and_cv_facts_can_justify_a_score(api) -> None:  # type: ignore[no-untyped-def]
    """A draft the user has not confirmed is not evidence of anything."""
    client, _ = api
    seeded = await seed(required_skills=["Rust"], preferred_skills=[])

    async with get_sessionmaker()() as session:
        session.add(
            CandidateFact(
                profile_id=seeded["profile_id"],
                kind=FactKind.SKILL,
                value="Rust",
                provenance=FactProvenance.GENERATED_DRAFT,
            )
        )
        await session.commit()

    body = (await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={})).json()
    missing = [
        item["requirement"] for item in body["evidence"] if item["kind"] == "missing_requirement"
    ]
    assert "Rust" in missing


async def test_a_hard_blocker_rejects_and_says_why(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    seeded = await seed(country="Germany", city="Munich", remote_type=RemoteType.ONSITE)

    body = (await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={})).json()
    assert body["routing"] == MatchRouting.REJECTED.value
    assert body["hard_blockers"]
    assert body["hard_blockers"][0]["rule"] == "country"
    assert "Germany" in body["hard_blockers"][0]["reason"]
    # The score survives, so "would have been great but for location" is visible.
    assert body["score"] > 0


async def test_an_explanation_is_grounded_in_the_evidence(api) -> None:  # type: ignore[no-untyped-def]
    client, provider = api
    seeded = await seed()
    provider.queue(Completion(content=explanation_payload()))

    body = (
        await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={"explain": True})
    ).json()

    assert body["explanation"] == "Strong match on backend skills."
    assert body["explanation_data"]["strengths"] == ["Python is confirmed"]
    assert body["explanation_data"]["dropped"] == []


async def test_a_failed_explanation_still_stores_the_score(api) -> None:  # type: ignore[no-untyped-def]
    client, provider = api
    seeded = await seed()
    provider.queue(Completion(content="not json"))
    provider.queue(Completion(content="still not json"))

    body = (
        await client.post(f"/api/v1/jobs/{seeded['job_id']}/score", json={"explain": True})
    ).json()
    assert body["score"] > 0
    assert body["explanation_data"]["error"]


async def test_scoring_a_missing_job_is_a_404(api) -> None:  # type: ignore[no-untyped-def]
    import uuid

    client, _ = api
    assert (await client.post(f"/api/v1/jobs/{uuid.uuid4()}/score", json={})).status_code == 404


async def test_an_unscored_job_has_no_match(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    seeded = await seed()
    response = await client.get(f"/api/v1/jobs/{seeded['job_id']}/match")
    assert response.status_code == 404
    assert "not been scored" in response.json()["detail"]


# --- the review queue -------------------------------------------------------


async def test_the_queue_hides_rejected_and_archived_by_default(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    good = await seed()
    async with get_sessionmaker()() as session:
        source = (await session.execute(select(JobSource))).scalars().one()
        session.add(
            Job(
                source_id=source.id,
                external_id="ext-2",
                company="Bright Smiles",
                title="Dental Hygienist",
                normalized_title="dental hygienist",
                description="Clean teeth.",
                application_url="https://boards.example.com/2",
                content_hash="b" * 64,
                country="Jordan",
                required_skills=["Dental hygiene"],
            )
        )
        await session.commit()

    await client.post("/api/v1/matches/run", json={})

    default = (await client.get("/api/v1/matches")).json()
    assert [item["job_id"] for item in default["items"]] == [str(good["job_id"])]
    assert default["counts_by_routing"]

    everything = (await client.get("/api/v1/matches", params={"include_rejected": True})).json()
    assert everything["total"] == 2


async def test_queue_rows_carry_scannable_reasons(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    await seed()
    await client.post("/api/v1/matches/run", json={})

    row = (await client.get("/api/v1/matches")).json()["items"][0]
    assert row["top_strengths"]
    assert row["shortlisted"] is False


async def test_a_run_reuses_scores_on_the_second_pass(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    await seed()

    first = (await client.post("/api/v1/matches/run", json={})).json()
    second = (await client.post("/api/v1/matches/run", json={})).json()

    assert first["scored"] == 1
    assert second["scored"] == 0
    assert second["reused"] == 1


async def test_matches_can_be_filtered_by_routing_and_score(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    await seed()
    await client.post("/api/v1/matches/run", json={})

    high = (await client.get("/api/v1/matches", params={"min_score": 200 - 100})).json()
    assert high["total"] == 0

    routed = (
        await client.get("/api/v1/matches", params={"routing": MatchRouting.HIGH_PRIORITY.value})
    ).json()
    assert routed["total"] <= 1


# --- shortlisting -----------------------------------------------------------


async def test_shortlisting_creates_an_application_and_is_idempotent(api) -> None:  # type: ignore[no-untyped-def]
    client, _ = api
    seeded = await seed()

    first = await client.post(f"/api/v1/jobs/{seeded['job_id']}/shortlist")
    assert first.status_code == 201
    assert first.json()["created"] is True
    assert first.json()["status"] == ApplicationStatus.SHORTLISTED.value

    second = await client.post(f"/api/v1/jobs/{seeded['job_id']}/shortlist")
    assert second.json()["created"] is False
    assert second.json()["application_id"] == first.json()["application_id"]

    async with get_sessionmaker()() as session:
        assert await session.scalar(select(func.count()).select_from(Application)) == 1


async def test_shortlisting_does_not_leave_the_machine(api) -> None:  # type: ignore[no-untyped-def]
    """It records intent. Nothing reaches an employer at this stage."""
    client, _ = api
    seeded = await seed()
    await client.post(f"/api/v1/jobs/{seeded['job_id']}/shortlist")

    async with get_sessionmaker()() as session:
        application = (await session.execute(select(Application))).scalars().one()
        assert application.status is ApplicationStatus.SHORTLISTED
        assert application.submitted_at is None
        assert application.approved_pack_hash is None
