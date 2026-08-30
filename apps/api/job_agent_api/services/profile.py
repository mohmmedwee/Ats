"""Profile and resume services.

The rules that matter live here rather than in the routers, so that chat tools
and background workers reach them the same way an HTTP request does.
"""

from __future__ import annotations

import hashlib
import uuid
from datetime import UTC, datetime
from pathlib import Path

from job_agent_ai.provider import AIProvider
from job_agent_ai.structured import StructuredOutputError
from job_agent_cv.errors import CVIngestionError
from job_agent_cv.extract import extract
from job_agent_cv.merge import ExistingFact, MergePlan, merge_profile_fields, plan_merge
from job_agent_cv.parser import ParseResult, build_facts, parse_profile
from job_agent_domain.crypto import decrypt_bytes, encrypt_bytes
from job_agent_domain.enums import FactKind, FactProvenance, ResumeParseStatus
from job_agent_domain.models import (
    AnswerBankEntry,
    AuditEvent,
    CandidateFact,
    CandidateProfile,
    ResumeFile,
    User,
)
from job_agent_domain.settings import Settings
from job_agent_observability import get_logger
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

log = get_logger("profile")

#: Profile fields a parse may populate. Anything outside this set is the user's.
PARSEABLE_FIELDS = ("headline", "location", "years_experience")


class DuplicateResumeError(ValueError):
    """This exact file has already been uploaded."""

    def __init__(self, resume_id: uuid.UUID) -> None:
        super().__init__("this file has already been uploaded")
        self.resume_id = resume_id


async def get_or_create_local_user(session: AsyncSession, settings: Settings) -> User:
    """Resolve the single local account.

    Authentication is not implemented yet. Isolating the lookup here means the
    rest of the code already takes a user, so adding real auth changes this
    function rather than every query.
    """
    result = await session.execute(select(User).where(User.email == settings.local_user_email))
    user = result.scalar_one_or_none()
    if user is None:
        user = User(email=settings.local_user_email, display_name=settings.local_user_name)
        session.add(user)
        await session.flush()
    return user


async def get_or_create_profile(session: AsyncSession, user: User) -> CandidateProfile:
    result = await session.execute(
        select(CandidateProfile).where(CandidateProfile.user_id == user.id)
    )
    profile = result.scalar_one_or_none()
    if profile is None:
        profile = CandidateProfile(user_id=user.id, preferences={}, locked_fields=[])
        session.add(profile)
        await session.flush()
    return profile


async def load_facts(session: AsyncSession, profile: CandidateProfile) -> list[CandidateFact]:
    result = await session.execute(
        select(CandidateFact)
        .where(CandidateFact.profile_id == profile.id)
        .order_by(CandidateFact.kind, CandidateFact.sort_order, CandidateFact.created_at)
    )
    return list(result.scalars())


def _storage_path(settings: Settings, user_id: uuid.UUID, digest: str) -> Path:
    return Path(settings.storage_dir) / "resumes" / str(user_id) / f"{digest}.enc"


async def store_resume(
    session: AsyncSession,
    *,
    user: User,
    settings: Settings,
    filename: str,
    content_type: str,
    data: bytes,
) -> ResumeFile:
    """Persist an upload and extract its text. Raises on anything unreadable.

    The file is written encrypted, and the extracted text is encrypted by its
    column type. A CV is the most identifying document this system holds.
    """
    document = extract(data, filename=filename, max_bytes=settings.max_resume_bytes)
    digest = hashlib.sha256(data).hexdigest()

    existing = await session.execute(
        select(ResumeFile).where(ResumeFile.user_id == user.id, ResumeFile.sha256 == digest)
    )
    duplicate = existing.scalar_one_or_none()
    if duplicate is not None:
        raise DuplicateResumeError(duplicate.id)

    path = _storage_path(settings, user.id, digest)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(encrypt_bytes(data))

    # A newly uploaded CV becomes the primary one; older ones stay for evidence.
    previous = await session.execute(
        select(ResumeFile).where(ResumeFile.user_id == user.id, ResumeFile.is_primary.is_(True))
    )
    for resume in previous.scalars():
        resume.is_primary = False

    resume = ResumeFile(
        user_id=user.id,
        filename=filename,
        content_type=content_type,
        byte_size=len(data),
        sha256=digest,
        storage_path=str(path),
        extracted_text=document.text,
        parse_status=ResumeParseStatus.TEXT_EXTRACTED,
        is_primary=True,
    )
    session.add(resume)
    await session.flush()

    session.add(
        AuditEvent(
            user_id=user.id,
            action="resume.uploaded",
            subject_type="resume_file",
            subject_id=str(resume.id),
            payload={"filename": filename, "bytes": len(data), "sha256": digest},
        )
    )
    return resume


def read_resume_bytes(resume: ResumeFile) -> bytes:
    return decrypt_bytes(Path(resume.storage_path).read_bytes())


async def apply_parse(
    session: AsyncSession,
    *,
    profile: CandidateProfile,
    resume: ResumeFile,
    parse: ParseResult,
) -> MergePlan:
    """Write a parse into the profile without discarding the user's work.

    Confirmed facts are kept. Facts from an earlier parse that this one no
    longer supports are removed, because a profile that keeps claims the current
    CV does not make is how a fabricated line ends up in an application.
    """
    stored = await load_facts(session, profile)
    plan = plan_merge(
        existing=[
            ExistingFact(id=str(f.id), kind=str(f.kind), value=f.value, provenance=f.provenance)
            for f in stored
        ],
        drafts=parse.facts,
    )

    by_id = {str(fact.id): fact for fact in stored}
    for withdrawn in plan.to_withdraw:
        await session.delete(by_id[withdrawn.id])

    for draft in plan.to_insert:
        session.add(
            CandidateFact(
                profile_id=profile.id,
                kind=draft.kind,
                value=draft.value,
                provenance=draft.provenance,
                evidence_ref=draft.evidence_ref,
                source_resume_id=resume.id,
                sort_order=draft.sort_order,
            )
        )

    parsed_fields: dict[str, object] = {
        "headline": parse.extraction.headline,
        "location": parse.extraction.location,
        "years_experience": parse.extraction.years_experience,
    }
    current = {name: getattr(profile, name) for name in PARSEABLE_FIELDS}
    merged = merge_profile_fields(current, parsed_fields, profile.locked_fields)
    for name, value in merged.items():
        setattr(profile, name, value)

    resume.parse_status = ResumeParseStatus.PARSED
    resume.parsed_at = datetime.now(UTC)
    resume.parse_error = None

    session.add(
        AuditEvent(
            user_id=profile.user_id,
            action="resume.parsed",
            subject_type="resume_file",
            subject_id=str(resume.id),
            payload={
                "facts_added": len(plan.to_insert),
                "facts_withdrawn": len(plan.to_withdraw),
                "facts_kept": len(plan.kept),
                "rejected": [{"kind": str(k), "value": v} for k, v in parse.rejected],
            },
        )
    )
    return plan


async def parse_resume(
    session: AsyncSession,
    *,
    provider: AIProvider,
    profile: CandidateProfile,
    resume: ResumeFile,
) -> tuple[ParseResult | None, MergePlan | None, str | None]:
    """Run extraction and merge. Returns the error instead of raising, so a bad
    parse leaves a readable status on the resume rather than a 500."""
    if not resume.extracted_text:
        resume.parse_status = ResumeParseStatus.FAILED
        resume.parse_error = "no extracted text; re-upload the file"
        return None, None, resume.parse_error

    document = extract(read_resume_bytes(resume), filename=resume.filename)
    try:
        extraction = await parse_profile(provider, document)
    except (StructuredOutputError, CVIngestionError) as exc:
        resume.parse_status = ResumeParseStatus.FAILED
        resume.parse_error = str(exc)
        log.warning("resume_parse_failed", resume_id=str(resume.id), error=str(exc))
        return None, None, str(exc)

    parse = build_facts(extraction, document)
    plan = await apply_parse(session, profile=profile, resume=resume, parse=parse)
    return parse, plan, None


async def confirm_fact(session: AsyncSession, fact: CandidateFact) -> CandidateFact:
    """Promote a fact to user-confirmed. The only path to that provenance."""
    fact.provenance = FactProvenance.USER_CONFIRMED
    fact.confirmed_at = datetime.now(UTC)
    session.add(
        AuditEvent(
            user_id=None,
            action="fact.confirmed",
            subject_type="candidate_fact",
            subject_id=str(fact.id),
            payload={"kind": str(fact.kind), "value": fact.value},
        )
    )
    return fact


async def add_user_fact(
    session: AsyncSession,
    *,
    profile: CandidateProfile,
    kind: FactKind,
    value: str,
    evidence_ref: str | None = None,
) -> CandidateFact:
    fact = CandidateFact(
        profile_id=profile.id,
        kind=kind,
        value=value,
        provenance=FactProvenance.USER_CONFIRMED,
        confirmed_at=datetime.now(UTC),
        evidence_ref=evidence_ref,
    )
    session.add(fact)
    await session.flush()
    return fact


def normalise_question(question: str) -> str:
    """Collapse a question to a lookup key.

    'Are you legally authorized to work in the US?' and 'Are you legally
    authorised to work in the US' should find the same stored answer.
    """
    lowered = question.casefold().strip().rstrip("?").strip()
    return " ".join(
        character for character in lowered if character.isalnum() or character.isspace()
    ).strip()


async def upsert_answer(
    session: AsyncSession,
    *,
    profile: CandidateProfile,
    question: str,
    answer: str,
    confirmed: bool,
) -> AnswerBankEntry:
    key = normalise_question(question)
    result = await session.execute(
        select(AnswerBankEntry).where(
            AnswerBankEntry.profile_id == profile.id, AnswerBankEntry.question_key == key
        )
    )
    entry = result.scalar_one_or_none()
    now = datetime.now(UTC)
    provenance = FactProvenance.USER_CONFIRMED if confirmed else FactProvenance.GENERATED_DRAFT

    if entry is None:
        entry = AnswerBankEntry(
            profile_id=profile.id,
            question=question,
            question_key=key,
            answer=answer,
            provenance=provenance,
            confirmed_at=now if confirmed else None,
        )
        session.add(entry)
        await session.flush()
    else:
        entry.question = question
        entry.answer = answer
        entry.provenance = provenance
        entry.confirmed_at = now if confirmed else None
    return entry
