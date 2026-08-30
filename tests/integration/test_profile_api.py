"""Resume ingestion, profile editing, and the answer bank end to end."""

from __future__ import annotations

import json
import pathlib
from typing import Any

import httpx
import pytest
from job_agent_ai import Completion, FakeProvider
from job_agent_cv.schema import ExtractedEducation, ExtractedProfile, ExtractedRole
from job_agent_domain.enums import FactProvenance, ResumeParseStatus

pytestmark = pytest.mark.integration

FIXTURE = (
    pathlib.Path(__file__).resolve().parents[2]
    / "fixtures"
    / "resumes"
    / "sample_engineering_lead.docx"
)


def extraction(**overrides: Any) -> ExtractedProfile:
    base = ExtractedProfile(
        headline="Engineering Lead / Senior Backend Engineer",
        summary="Engineering lead with seven years building distributed systems.",
        location="Amman, Jordan",
        years_experience=7,
        skills=["Python", "FastAPI", "Kubernetes"],
        roles=[
            ExtractedRole(
                company="Northwind Systems",
                title="Engineering Lead",
                achievements=[
                    "Led a team of six engineers delivering a multi-tenant SaaS platform."
                ],
            )
        ],
        education=[
            ExtractedEducation(
                institution="University of Jordan", qualification="BSc Computer Science"
            )
        ],
        certifications=["Certified Kubernetes Application Developer"],
        languages=["Arabic", "English"],
    )
    return base.model_copy(update=overrides)


def script(provider: FakeProvider, profile: ExtractedProfile) -> None:
    provider.queue(Completion(content=json.dumps(profile.model_dump(mode="json"))))


def upload(client: httpx.AsyncClient, *, name: str = "cv.docx", data: bytes | None = None):  # type: ignore[no-untyped-def]
    payload = data if data is not None else FIXTURE.read_bytes()
    return client.post(
        "/api/v1/resumes",
        files={
            "file": (
                name,
                payload,
                "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            )
        },
    )


# --- upload and parse -------------------------------------------------------


async def test_upload_parses_the_cv_into_facts(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, provider = profile_client
    script(provider, extraction())

    response = await upload(client)
    assert response.status_code == 201
    report = response.json()
    assert report["status"] == ResumeParseStatus.PARSED.value
    assert report["facts_added"] > 0
    assert report["rejected"] == []

    profile = (await client.get("/api/v1/profile")).json()
    values = {fact["value"] for fact in profile["facts"]}
    assert {"Python", "FastAPI", "Kubernetes"} <= values
    assert "Engineering Lead at Northwind Systems" in values
    assert profile["location"] == "Amman, Jordan"


async def test_claims_the_cv_does_not_support_are_reported_not_stored(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, provider = profile_client
    script(provider, extraction(skills=["Python", "Rust"], certifications=["AWS Certified DevOps"]))

    report = (await upload(client)).json()
    rejected = {claim["value"] for claim in report["rejected"]}
    assert {"Rust", "AWS Certified DevOps"} <= rejected

    profile = (await client.get("/api/v1/profile")).json()
    assert "Rust" not in {fact["value"] for fact in profile["facts"]}


async def test_no_parsed_fact_is_stored_as_user_confirmed(profile_client) -> None:  # type: ignore[no-untyped-def]
    """Phase 1 acceptance."""
    client, provider = profile_client
    script(provider, extraction())
    await upload(client)

    profile = (await client.get("/api/v1/profile")).json()
    assert profile["facts"]
    assert all(
        fact["provenance"] != FactProvenance.USER_CONFIRMED.value for fact in profile["facts"]
    )
    assert all(fact["confirmed_at"] is None for fact in profile["facts"])


async def test_a_model_that_returns_prose_leaves_a_readable_failure(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, provider = profile_client
    provider.queue(Completion(content="I think this person is an engineer."))
    provider.queue(Completion(content="Still not JSON."))

    response = await upload(client)
    assert response.status_code == 201
    report = response.json()
    assert report["status"] == ResumeParseStatus.FAILED.value
    assert report["error"]

    resumes = (await client.get("/api/v1/resumes")).json()
    assert resumes[0]["parse_status"] == ResumeParseStatus.FAILED.value
    assert resumes[0]["parse_error"]


# --- upload validation ------------------------------------------------------


async def test_the_same_file_twice_is_a_conflict(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, provider = profile_client
    script(provider, extraction())
    await upload(client)

    response = await upload(client, name="copy.docx")
    assert response.status_code == 409
    assert "resume_id" in response.json()["detail"]


async def test_a_file_that_is_not_a_document_is_rejected(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, _ = profile_client
    response = await upload(client, name="cv.docx", data=b"#!/bin/sh\necho not a cv\n")
    assert response.status_code == 400


async def test_an_oversize_upload_is_rejected(profile_client, monkeypatch) -> None:  # type: ignore[no-untyped-def]
    client, _ = profile_client
    from job_agent_domain.settings import get_settings

    monkeypatch.setattr(get_settings(), "max_resume_bytes", 512)
    response = await upload(client)
    assert response.status_code == 413


async def test_a_scanned_pdf_is_reported_as_needing_ocr(profile_client, make_pdf) -> None:  # type: ignore[no-untyped-def]
    client, _ = profile_client
    response = await upload(client, name="scan.pdf", data=make_pdf("x"))
    assert response.status_code == 422
    assert "OCR" in response.json()["detail"]


async def test_upload_requires_an_idempotency_key(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, _ = profile_client
    response = await client.post(
        "/api/v1/resumes",
        files={"file": ("cv.docx", FIXTURE.read_bytes(), "application/octet-stream")},
        headers={"Idempotency-Key": ""},
    )
    assert response.status_code == 428


# --- edits surviving reprocessing -------------------------------------------


async def test_editing_a_profile_field_locks_it_against_reparsing(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, provider = profile_client
    script(provider, extraction())
    resume_id = (await upload(client)).json()["resume_id"]

    patched = await client.patch("/api/v1/profile", json={"headline": "Principal Engineer"})
    assert patched.status_code == 200
    assert patched.json()["locked_fields"] == ["headline"]

    script(provider, extraction(headline="Junior Developer", location="Dubai"))
    await client.post(f"/api/v1/resumes/{resume_id}/parse")

    profile = (await client.get("/api/v1/profile")).json()
    # The locked field keeps the user's wording; the unlocked one follows the
    # new parse, which is the whole point of locking being per field.
    assert profile["headline"] == "Principal Engineer"
    assert profile["location"] == "Dubai"


async def test_a_confirmed_fact_survives_a_reparse_that_drops_it(profile_client) -> None:  # type: ignore[no-untyped-def]
    """Phase 1 acceptance: user edits survive reprocessing."""
    client, provider = profile_client
    script(provider, extraction())
    resume_id = (await upload(client)).json()["resume_id"]

    profile = (await client.get("/api/v1/profile")).json()
    kubernetes = next(f for f in profile["facts"] if f["value"] == "Kubernetes")
    confirmed = await client.post(f"/api/v1/profile/facts/{kubernetes['id']}/confirm")
    assert confirmed.json()["provenance"] == FactProvenance.USER_CONFIRMED.value

    script(provider, extraction(skills=["Python"]))
    report = (await client.post(f"/api/v1/resumes/{resume_id}/parse")).json()
    assert report["facts_withdrawn"] >= 1

    values = {f["value"] for f in (await client.get("/api/v1/profile")).json()["facts"]}
    assert "Kubernetes" in values
    assert "FastAPI" not in values


async def test_rewriting_a_fact_makes_it_the_users_claim(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, provider = profile_client
    script(provider, extraction())
    await upload(client)

    profile = (await client.get("/api/v1/profile")).json()
    fact = next(f for f in profile["facts"] if f["value"] == "Python")
    updated = await client.patch(
        f"/api/v1/profile/facts/{fact['id']}", json={"value": "Python 3.12"}
    )
    assert updated.json()["value"] == "Python 3.12"
    assert updated.json()["provenance"] == FactProvenance.USER_CONFIRMED.value


async def test_a_hand_added_fact_is_user_confirmed(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, _ = profile_client
    response = await client.post(
        "/api/v1/profile/facts", json={"kind": "skill", "value": "Public speaking"}
    )
    assert response.status_code == 201
    assert response.json()["provenance"] == FactProvenance.USER_CONFIRMED.value
    assert response.json()["confirmed_at"]


async def test_a_fact_can_be_deleted(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, _ = profile_client
    fact = (
        await client.post("/api/v1/profile/facts", json={"kind": "skill", "value": "Fortran"})
    ).json()
    assert (await client.delete(f"/api/v1/profile/facts/{fact['id']}")).status_code == 204
    values = {f["value"] for f in (await client.get("/api/v1/profile")).json()["facts"]}
    assert "Fortran" not in values


# --- answer bank ------------------------------------------------------------


async def test_answers_are_matched_across_rewordings(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, _ = profile_client
    first = await client.post(
        "/api/v1/answers",
        json={"question": "Are you legally authorised to work in Jordan?", "answer": "Yes"},
    )
    assert first.status_code == 200

    second = await client.post(
        "/api/v1/answers",
        json={"question": "are you legally authorised to work in Jordan", "answer": "Yes, citizen"},
    )
    assert second.json()["id"] == first.json()["id"]

    answers = (await client.get("/api/v1/answers")).json()
    assert len(answers) == 1
    assert answers[0]["answer"] == "Yes, citizen"


async def test_an_unconfirmed_answer_is_stored_as_a_draft(profile_client) -> None:  # type: ignore[no-untyped-def]
    client, _ = profile_client
    response = await client.post(
        "/api/v1/answers",
        json={"question": "Why this company?", "answer": "Draft text", "confirmed": False},
    )
    assert response.json()["provenance"] == FactProvenance.GENERATED_DRAFT.value
    assert response.json()["confirmed_at"] is None


# --- data at rest -----------------------------------------------------------


async def test_the_cv_and_its_text_are_encrypted_on_disk(profile_client) -> None:  # type: ignore[no-untyped-def]
    """Plan section 10: a CV is the most identifying document here."""
    import pathlib

    from job_agent_domain.db import get_sessionmaker
    from sqlalchemy import text

    client, provider = profile_client
    script(provider, extraction())
    await upload(client)

    async with get_sessionmaker()() as session:
        row = (
            await session.execute(
                text("SELECT storage_path, extracted_text FROM resume_files LIMIT 1")
            )
        ).one()

    storage_path, stored_text = row
    # The column holds a token, not the CV.
    assert "Northwind Systems" not in stored_text
    assert stored_text.startswith("gAAAAA")

    # Blocking read in an async test is fine here: it is a few KB from tmp_path.
    on_disk = pathlib.Path(storage_path).read_bytes()  # noqa: ASYNC240
    assert on_disk[:4] != b"PK\x03\x04"
    assert b"Northwind" not in on_disk


async def test_the_extracted_text_reads_back_as_plaintext(profile_client) -> None:  # type: ignore[no-untyped-def]
    """Encryption is transparent to the application, opaque to the database."""
    from job_agent_domain.db import get_sessionmaker
    from job_agent_domain.models import ResumeFile
    from sqlalchemy import select

    client, provider = profile_client
    script(provider, extraction())
    await upload(client)

    async with get_sessionmaker()() as session:
        resume = (await session.execute(select(ResumeFile))).scalars().one()
        assert resume.extracted_text is not None
        assert "Northwind Systems" in resume.extracted_text
