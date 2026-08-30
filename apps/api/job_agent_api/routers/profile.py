"""Resume upload, candidate profile, and the answer bank."""

from __future__ import annotations

import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, File, HTTPException, Response, UploadFile, status
from job_agent_ai.provider import AIProvider
from job_agent_cv.errors import CVIngestionError, FileTooLargeError, UnsupportedFormatError
from job_agent_domain.models import AnswerBankEntry, AuditEvent, CandidateFact, ResumeFile
from job_agent_domain.settings import Settings
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from job_agent_api.dependencies import get_ai_provider, get_app_settings, get_session
from job_agent_api.schemas.profile import (
    AnswerRead,
    AnswerUpsert,
    FactCreate,
    FactRead,
    FactUpdate,
    ParseReport,
    ProfileRead,
    ProfileUpdate,
    RejectedClaim,
    ResumeRead,
)
from job_agent_api.services import profile as service

router = APIRouter(prefix="/api/v1", tags=["profile"])

SessionDep = Annotated[AsyncSession, Depends(get_session)]
SettingsDep = Annotated[Settings, Depends(get_app_settings)]
ProviderDep = Annotated[AIProvider, Depends(get_ai_provider)]


async def _profile_response(session: AsyncSession, settings: Settings) -> ProfileRead:
    user = await service.get_or_create_local_user(session, settings)
    profile = await service.get_or_create_profile(session, user)
    facts = await service.load_facts(session, profile)
    return ProfileRead(
        id=profile.id,
        headline=profile.headline,
        location=profile.location,
        years_experience=profile.years_experience,
        preferences=profile.preferences,
        locked_fields=profile.locked_fields,
        version=profile.version,
        facts=[FactRead.model_validate(fact) for fact in facts],
    )


# --- resumes ----------------------------------------------------------------


@router.post("/resumes", response_model=ParseReport, status_code=status.HTTP_201_CREATED)
async def upload_resume(
    session: SessionDep,
    settings: SettingsDep,
    provider: ProviderDep,
    file: Annotated[UploadFile, File()],
) -> ParseReport:
    """Upload a CV, extract its text, and parse it into facts.

    The upload is read with a hard cap: a client that lies about its length
    should not be able to fill the disk before the check runs.
    """
    data = await file.read(settings.max_resume_bytes + 1)
    if len(data) > settings.max_resume_bytes:
        raise HTTPException(
            status_code=status.HTTP_413_CONTENT_TOO_LARGE,
            detail=f"file exceeds the {settings.max_resume_bytes} byte limit",
        )

    user = await service.get_or_create_local_user(session, settings)
    candidate_profile = await service.get_or_create_profile(session, user)

    try:
        resume = await service.store_resume(
            session,
            user=user,
            settings=settings,
            filename=file.filename or "resume",
            content_type=file.content_type or "application/octet-stream",
            data=data,
        )
    except service.DuplicateResumeError as exc:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={"message": str(exc), "resume_id": str(exc.resume_id)},
        ) from exc
    except (UnsupportedFormatError, FileTooLargeError) as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc
    except CVIngestionError as exc:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT, detail=str(exc)
        ) from exc

    parse, plan, error = await service.parse_resume(
        session, provider=provider, profile=candidate_profile, resume=resume
    )
    await session.commit()

    return ParseReport(
        resume_id=resume.id,
        status=resume.parse_status,
        facts_added=len(plan.to_insert) if plan else 0,
        facts_withdrawn=len(plan.to_withdraw) if plan else 0,
        facts_kept=len(plan.kept) if plan else 0,
        rejected=[RejectedClaim(kind=k, value=v) for k, v in (parse.rejected if parse else [])],
        error=error,
    )


@router.get("/resumes", response_model=list[ResumeRead])
async def list_resumes(session: SessionDep, settings: SettingsDep) -> list[ResumeFile]:
    user = await service.get_or_create_local_user(session, settings)
    result = await session.execute(
        select(ResumeFile)
        .where(ResumeFile.user_id == user.id)
        .order_by(ResumeFile.created_at.desc())
    )
    await session.commit()
    return list(result.scalars())


@router.post("/resumes/{resume_id}/parse", response_model=ParseReport)
async def reparse_resume(
    resume_id: uuid.UUID,
    session: SessionDep,
    settings: SettingsDep,
    provider: ProviderDep,
) -> ParseReport:
    """Re-run parsing over a stored CV.

    Confirmed facts and locked profile fields are preserved; see
    ``services.profile.apply_parse``.
    """
    user = await service.get_or_create_local_user(session, settings)
    candidate_profile = await service.get_or_create_profile(session, user)
    resume = await session.get(ResumeFile, resume_id)
    if resume is None or resume.user_id != user.id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="resume not found")

    parse, plan, error = await service.parse_resume(
        session, provider=provider, profile=candidate_profile, resume=resume
    )
    await session.commit()
    return ParseReport(
        resume_id=resume.id,
        status=resume.parse_status,
        facts_added=len(plan.to_insert) if plan else 0,
        facts_withdrawn=len(plan.to_withdraw) if plan else 0,
        facts_kept=len(plan.kept) if plan else 0,
        rejected=[RejectedClaim(kind=k, value=v) for k, v in (parse.rejected if parse else [])],
        error=error,
    )


# --- profile ----------------------------------------------------------------


@router.get("/profile", response_model=ProfileRead)
async def read_profile(session: SessionDep, settings: SettingsDep) -> ProfileRead:
    response = await _profile_response(session, settings)
    await session.commit()
    return response


@router.patch("/profile", response_model=ProfileRead)
async def update_profile(
    payload: ProfileUpdate, session: SessionDep, settings: SettingsDep
) -> ProfileRead:
    user = await service.get_or_create_local_user(session, settings)
    candidate_profile = await service.get_or_create_profile(session, user)

    changes = payload.model_dump(exclude_unset=True)
    locked = set(candidate_profile.locked_fields)
    for name, value in changes.items():
        setattr(candidate_profile, name, value)
        if name in service.PARSEABLE_FIELDS:
            # Editing a field is a statement that the parse got it wrong.
            locked.add(name)
    candidate_profile.locked_fields = sorted(locked)
    candidate_profile.version += 1

    session.add(
        AuditEvent(
            user_id=user.id,
            action="profile.updated",
            subject_type="candidate_profile",
            subject_id=str(candidate_profile.id),
            payload={"fields": sorted(changes), "locked_fields": candidate_profile.locked_fields},
        )
    )
    response = await _profile_response(session, settings)
    await session.commit()
    return response


# --- facts ------------------------------------------------------------------


@router.post("/profile/facts", response_model=FactRead, status_code=status.HTTP_201_CREATED)
async def create_fact(
    payload: FactCreate, session: SessionDep, settings: SettingsDep
) -> CandidateFact:
    user = await service.get_or_create_local_user(session, settings)
    candidate_profile = await service.get_or_create_profile(session, user)
    fact = await service.add_user_fact(
        session,
        profile=candidate_profile,
        kind=payload.kind,
        value=payload.value,
        evidence_ref=payload.evidence_ref,
    )
    await session.commit()
    return fact


@router.post("/profile/facts/{fact_id}/confirm", response_model=FactRead)
async def confirm_fact(fact_id: uuid.UUID, session: SessionDep) -> CandidateFact:
    fact = await session.get(CandidateFact, fact_id)
    if fact is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="fact not found")
    await service.confirm_fact(session, fact)
    await session.commit()
    return fact


@router.patch("/profile/facts/{fact_id}", response_model=FactRead)
async def update_fact(
    fact_id: uuid.UUID, payload: FactUpdate, session: SessionDep
) -> CandidateFact:
    fact = await session.get(CandidateFact, fact_id)
    if fact is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="fact not found")
    changes = payload.model_dump(exclude_unset=True)
    if "value" in changes:
        # Rewriting a fact makes it the user's claim, not the parser's.
        fact.value = changes["value"]
        await service.confirm_fact(session, fact)
    if "sort_order" in changes:
        fact.sort_order = changes["sort_order"]
    await session.commit()
    return fact


@router.delete("/profile/facts/{fact_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_fact(fact_id: uuid.UUID, session: SessionDep) -> Response:
    fact = await session.get(CandidateFact, fact_id)
    if fact is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="fact not found")
    await session.delete(fact)
    await session.commit()
    return Response(status_code=status.HTTP_204_NO_CONTENT)


# --- answer bank ------------------------------------------------------------


@router.get("/answers", response_model=list[AnswerRead])
async def list_answers(session: SessionDep, settings: SettingsDep) -> list[AnswerBankEntry]:
    user = await service.get_or_create_local_user(session, settings)
    candidate_profile = await service.get_or_create_profile(session, user)
    result = await session.execute(
        select(AnswerBankEntry)
        .where(AnswerBankEntry.profile_id == candidate_profile.id)
        .order_by(AnswerBankEntry.question_key)
    )
    await session.commit()
    return list(result.scalars())


@router.post("/answers", response_model=AnswerRead)
async def upsert_answer(
    payload: AnswerUpsert, session: SessionDep, settings: SettingsDep
) -> AnswerBankEntry:
    user = await service.get_or_create_local_user(session, settings)
    candidate_profile = await service.get_or_create_profile(session, user)
    entry = await service.upsert_answer(
        session,
        profile=candidate_profile,
        question=payload.question,
        answer=payload.answer,
        confirmed=payload.confirmed,
    )
    await session.commit()
    return entry
