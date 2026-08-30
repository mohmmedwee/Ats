"""Parsing a CV into facts, and refusing to keep anything it does not support."""

from __future__ import annotations

import json
import pathlib

import pytest
from job_agent_ai import Completion, FakeProvider
from job_agent_cv.extract import extract
from job_agent_cv.parser import build_facts, is_supported, parse_profile
from job_agent_cv.schema import ExtractedEducation, ExtractedProfile, ExtractedRole
from job_agent_domain.enums import FactKind, FactProvenance

FIXTURE = (
    pathlib.Path(__file__).resolve().parents[2]
    / "fixtures"
    / "resumes"
    / "sample_engineering_lead.docx"
)


@pytest.fixture
def document():  # type: ignore[no-untyped-def]
    return extract(FIXTURE.read_bytes())


def honest_extraction() -> ExtractedProfile:
    return ExtractedProfile(
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
        links=["github.com/sample-candidate"],
    )


# --- grounding --------------------------------------------------------------


def test_supported_values_become_cv_derived_facts(document) -> None:  # type: ignore[no-untyped-def]
    result = build_facts(honest_extraction(), document)
    skills = {f.value for f in result.facts if f.kind is FactKind.SKILL}
    assert skills == {"Python", "FastAPI", "Kubernetes"}
    assert all(
        f.provenance is FactProvenance.CV_DERIVED for f in result.facts if f.kind is FactKind.SKILL
    )
    assert result.rejected == []


def test_invented_values_are_rejected_not_stored(document) -> None:  # type: ignore[no-untyped-def]
    extraction = honest_extraction()
    extraction.skills = ["Python", "Rust", "COBOL"]
    extraction.certifications = ["AWS Certified Solutions Architect"]
    extraction.roles.append(ExtractedRole(company="Initech", title="Principal Engineer"))

    result = build_facts(extraction, document)

    stored = {f.value for f in result.facts}
    assert "Rust" not in stored
    assert "COBOL" not in stored
    assert "Initech" not in stored
    assert "AWS Certified Solutions Architect" not in stored

    rejected = {value for _, value in result.rejected}
    assert {"Rust", "COBOL", "Initech", "AWS Certified Solutions Architect"} <= rejected


def test_an_invented_achievement_is_rejected(document) -> None:  # type: ignore[no-untyped-def]
    extraction = honest_extraction()
    extraction.roles[0].achievements.append("Saved the company 4 million dollars.")
    result = build_facts(extraction, document)
    assert "Saved the company 4 million dollars." not in {f.value for f in result.facts}


def test_no_parsed_fact_is_ever_user_confirmed(document) -> None:  # type: ignore[no-untyped-def]
    """Phase 1 acceptance: no generated claim is saved as user-confirmed."""
    extraction = honest_extraction()
    extraction.skills.append("Quantum Computing")
    result = build_facts(extraction, document)
    assert all(f.provenance is not FactProvenance.USER_CONFIRMED for f in result.facts)


def test_paraphrased_fields_are_drafts_not_cv_derived(document) -> None:  # type: ignore[no-untyped-def]
    """A summary is necessarily the model's wording, so it cannot claim to be
    lifted from the CV."""
    result = build_facts(honest_extraction(), document)
    by_kind = {f.kind: f for f in result.facts}
    assert by_kind[FactKind.SUMMARY].provenance is FactProvenance.GENERATED_DRAFT
    assert by_kind[FactKind.HEADLINE].provenance is FactProvenance.GENERATED_DRAFT
    assert by_kind[FactKind.YEARS_EXPERIENCE].provenance is FactProvenance.GENERATED_DRAFT
    assert by_kind[FactKind.LOCATION].provenance is FactProvenance.CV_DERIVED


def test_composed_values_are_verified_by_their_parts(document) -> None:  # type: ignore[no-untyped-def]
    """ "Engineering Lead at Northwind Systems" never appears verbatim, but both
    halves do."""
    result = build_facts(honest_extraction(), document)
    roles = {f.value for f in result.facts if f.kind is FactKind.ROLE}
    assert "Engineering Lead at Northwind Systems" in roles

    education = {f.value for f in result.facts if f.kind is FactKind.EDUCATION}
    assert "BSc Computer Science, University of Jordan" in education


def test_matching_ignores_case_punctuation_and_accents() -> None:
    source_like = "skills python fastapi node js"
    assert is_supported("Python,", source_like)
    assert is_supported("Node.js", source_like)
    assert not is_supported("Ruby", source_like)


def test_empty_values_are_dropped(document) -> None:  # type: ignore[no-untyped-def]
    extraction = honest_extraction()
    extraction.skills = ["", "   ", "Python"]
    result = build_facts(extraction, document)
    assert [f.value for f in result.facts if f.kind is FactKind.SKILL] == ["Python"]


def test_facts_carry_evidence_references(document) -> None:  # type: ignore[no-untyped-def]
    result = build_facts(honest_extraction(), document)
    skill = next(f for f in result.facts if f.kind is FactKind.SKILL)
    assert skill.evidence_ref == "section:skills"


# --- the model call ---------------------------------------------------------


async def test_parse_profile_validates_against_the_schema(document) -> None:  # type: ignore[no-untyped-def]
    payload = json.dumps(honest_extraction().model_dump(mode="json"))
    provider = FakeProvider(completions=[Completion(content=payload)])
    parsed = await parse_profile(provider, document)
    assert parsed.location == "Amman, Jordan"
    assert "Python" in parsed.skills


async def test_the_cv_is_the_only_thing_sent(document) -> None:  # type: ignore[no-untyped-def]
    provider = FakeProvider(
        completions=[Completion(content=json.dumps(ExtractedProfile().model_dump(mode="json")))]
    )
    await parse_profile(provider, document)
    sent = "\n".join(message.content for message in provider.calls[0])
    assert "<cv>" in sent
    assert "Northwind Systems" in sent
