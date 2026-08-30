"""Scoring, the review queue, and shortlisting."""

from __future__ import annotations

import uuid
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from job_agent_ai.provider import AIProvider
from job_agent_domain.enums import ApplicationStatus, MatchRouting
from job_agent_domain.models import Application, AuditEvent, Job, JobMatch, MatchEvidence
from job_agent_domain.settings import Settings
from job_agent_matching import service as matching
from job_agent_matching.evidence import EvidenceKind
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from job_agent_api.dependencies import get_ai_provider, get_app_settings, get_session
from job_agent_api.schemas.matching import (
    EvidenceRead,
    MatchDetail,
    MatchPage,
    MatchSummary,
    ScoreRequest,
    ScoreRunReportRead,
    ScoreRunRequest,
)
from job_agent_api.services import profile as profile_service

router = APIRouter(prefix="/api/v1", tags=["matching"])

SessionDep = Annotated[AsyncSession, Depends(get_session)]
SettingsDep = Annotated[Settings, Depends(get_app_settings)]
ProviderDep = Annotated[AIProvider, Depends(get_ai_provider)]


async def _profile_and_facts(session: AsyncSession, settings: Settings):  # type: ignore[no-untyped-def]
    user = await profile_service.get_or_create_local_user(session, settings)
    profile = await profile_service.get_or_create_profile(session, user)
    facts = await profile_service.load_facts(session, profile)
    return user, profile, facts


def _top(evidence: list[MatchEvidence], kind: EvidenceKind, limit: int = 3) -> list[str]:
    return [item.requirement for item in evidence if item.kind == kind.value][:limit]


# --- scoring ----------------------------------------------------------------


@router.post("/jobs/{job_id}/score", response_model=MatchDetail)
async def score_job(
    job_id: uuid.UUID,
    payload: ScoreRequest,
    session: SessionDep,
    settings: SettingsDep,
    provider: ProviderDep,
) -> MatchDetail:
    job = await session.get(Job, job_id)
    if job is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="job not found")

    _, profile, facts = await _profile_and_facts(session, settings)
    outcome = await matching.score_one(
        session,
        job=job,
        profile=profile,
        facts=facts,
        provider=provider,
        embedding_model=settings.embedding_model,
        explain=payload.explain,
        force=payload.force,
    )
    await session.commit()
    return await _match_detail(session, outcome.match_id)


@router.post("/matches/run", response_model=ScoreRunReportRead)
async def run_scoring(
    payload: ScoreRunRequest,
    session: SessionDep,
    settings: SettingsDep,
    provider: ProviderDep,
) -> ScoreRunReportRead:
    """Score every discovered job against the current profile.

    Unchanged inputs reuse the stored score rather than recomputing it.
    """
    _, profile, facts = await _profile_and_facts(session, settings)
    report = await matching.score_all(
        session,
        profile=profile,
        facts=facts,
        provider=provider,
        embedding_model=settings.embedding_model,
        explain=payload.explain,
        limit=payload.limit,
    )
    await session.commit()
    return ScoreRunReportRead(
        scored=report.scored, reused=report.reused, explained=report.explained
    )


# --- the review queue -------------------------------------------------------


@router.get("/matches", response_model=MatchPage)
async def list_matches(
    session: SessionDep,
    routing: MatchRouting | None = None,
    min_score: Annotated[float | None, Query(ge=0, le=100)] = None,
    company: str | None = None,
    include_rejected: bool = False,
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
) -> MatchPage:
    query = select(JobMatch, Job).join(Job, Job.id == JobMatch.job_id)
    if routing is not None:
        query = query.where(JobMatch.routing == routing)
    elif not include_rejected:
        # Rejected and archived rows are noise in a queue meant for deciding.
        query = query.where(JobMatch.routing.notin_([MatchRouting.REJECTED, MatchRouting.ARCHIVED]))
    if min_score is not None:
        query = query.where(JobMatch.score >= min_score)
    if company:
        query = query.where(func.lower(Job.company) == company.lower())

    total = await session.scalar(select(func.count()).select_from(query.subquery())) or 0

    counts: dict[str, int] = {}
    for value, count in await session.execute(
        select(JobMatch.routing, func.count()).group_by(JobMatch.routing)
    ):
        counts[str(value)] = count

    rows = await session.execute(query.order_by(JobMatch.score.desc()).limit(limit).offset(offset))

    items: list[MatchSummary] = []
    for match, job in rows:
        evidence = list(
            (
                await session.execute(
                    select(MatchEvidence).where(MatchEvidence.match_id == match.id)
                )
            ).scalars()
        )
        shortlisted = await session.scalar(
            select(func.count()).select_from(Application).where(Application.job_id == job.id)
        )
        items.append(
            MatchSummary(
                match_id=match.id,
                job_id=job.id,
                score=match.score,
                routing=match.routing,
                company=job.company,
                title=job.title,
                location=job.location,
                remote_type=job.remote_type,
                seniority=job.seniority,
                application_url=job.application_url,
                posted_at=job.posted_at,
                injection_flagged=job.injection_flagged,
                top_strengths=_top(evidence, EvidenceKind.MATCHED_REQUIREMENT),
                top_gaps=_top(evidence, EvidenceKind.MISSING_REQUIREMENT),
                blocker_reasons=[
                    blocker["reason"]
                    for blocker in match.hard_blockers.get("blockers", [])
                    if isinstance(blocker, dict) and blocker.get("reason")
                ],
                shortlisted=bool(shortlisted),
            )
        )

    return MatchPage(items=items, total=total, limit=limit, offset=offset, counts_by_routing=counts)


async def _match_detail(session: AsyncSession, match_id: uuid.UUID) -> MatchDetail:
    match = await session.get(JobMatch, match_id)
    if match is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="match not found")
    evidence = await session.execute(
        select(MatchEvidence).where(MatchEvidence.match_id == match.id).order_by(MatchEvidence.kind)
    )
    blockers: list[dict[str, Any]] = list(match.hard_blockers.get("blockers", []))
    return MatchDetail(
        match_id=match.id,
        job_id=match.job_id,
        score=match.score,
        routing=match.routing,
        breakdown=match.breakdown,
        hard_blockers=blockers,
        evidence=[EvidenceRead.model_validate(row) for row in evidence.scalars()],
        explanation=match.explanation,
        explanation_data=match.explanation_data,
        semantic_similarity=match.semantic_similarity,
        embedding_model=match.embedding_model,
        inputs_hash=match.inputs_hash,
        scored_at=match.updated_at,
    )


@router.get("/jobs/{job_id}/match", response_model=MatchDetail)
async def read_match(job_id: uuid.UUID, session: SessionDep) -> MatchDetail:
    match = (
        (await session.execute(select(JobMatch).where(JobMatch.job_id == job_id))).scalars().first()
    )
    if match is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND, detail="this job has not been scored yet"
        )
    return await _match_detail(session, match.id)


# --- shortlisting -----------------------------------------------------------


@router.post("/jobs/{job_id}/shortlist", status_code=status.HTTP_201_CREATED)
async def shortlist_job(
    job_id: uuid.UUID, session: SessionDep, settings: SettingsDep
) -> dict[str, Any]:
    """Move a job into the pipeline.

    Shortlisting is a decision about intent, not an external action: it creates
    an application in the ``shortlisted`` state and nothing leaves the machine.
    """
    job = await session.get(Job, job_id)
    if job is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="job not found")

    user, _, _ = await _profile_and_facts(session, settings)
    existing = (
        (
            await session.execute(
                select(Application).where(
                    Application.job_id == job.id, Application.user_id == user.id
                )
            )
        )
        .scalars()
        .first()
    )
    if existing is not None:
        return {"application_id": str(existing.id), "status": existing.status, "created": False}

    application = Application(job_id=job.id, user_id=user.id, status=ApplicationStatus.SHORTLISTED)
    session.add(application)
    await session.flush()
    session.add(
        AuditEvent(
            user_id=user.id,
            action="job.shortlisted",
            subject_type="application",
            subject_id=str(application.id),
            payload={"job_id": str(job.id), "title": job.title, "company": job.company},
        )
    )
    await session.commit()
    return {
        "application_id": str(application.id),
        "status": application.status,
        "created": True,
    }
