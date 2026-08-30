"""Sources, discovery runs, and the discovered jobs."""

from __future__ import annotations

import uuid
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from job_agent_connectors.base import SourceConfigError
from job_agent_connectors.registry import SUPPORTED_KINDS, build_source
from job_agent_discovery import pipeline as service
from job_agent_domain.enums import RemoteType, Seniority
from job_agent_domain.models import AuditEvent, Job, JobRawSnapshot, JobSource
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from job_agent_api.dependencies import get_session
from job_agent_api.schemas.discovery import (
    DiscoveryReportRead,
    JobDetail,
    JobPage,
    JobRead,
    SnapshotRead,
    SourceCreate,
    SourceRead,
    SourceUpdate,
)

router = APIRouter(prefix="/api/v1", tags=["discovery"])

SessionDep = Annotated[AsyncSession, Depends(get_session)]


# --- sources ----------------------------------------------------------------


@router.get("/sources", response_model=list[SourceRead])
async def list_sources(session: SessionDep) -> list[JobSource]:
    result = await session.execute(select(JobSource).order_by(JobSource.name))
    return list(result.scalars())


@router.get("/sources/kinds")
async def list_source_kinds() -> dict[str, Any]:
    """What the UI can offer, and what each kind needs configured."""
    return {
        "kinds": [
            {
                "kind": "greenhouse",
                "required_config": ["board_token"],
                "optional_config": ["company"],
            },
            {"kind": "lever", "required_config": ["site"], "optional_config": ["company"]},
            {
                "kind": "ashby",
                "required_config": ["job_board_name"],
                "optional_config": ["company"],
            },
        ],
        "supported": list(SUPPORTED_KINDS),
    }


@router.post("/sources", response_model=SourceRead, status_code=status.HTTP_201_CREATED)
async def create_source(payload: SourceCreate, session: SessionDep) -> JobSource:
    # Build the connector now so a misconfigured source is rejected here rather
    # than failing silently on the next scheduled run.
    try:
        build_source(payload.kind, payload.config)
    except SourceConfigError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc

    existing = await session.execute(
        select(JobSource).where(JobSource.kind == payload.kind, JobSource.name == payload.name)
    )
    if existing.scalar_one_or_none() is not None:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail=f"a {payload.kind} source named {payload.name!r} already exists",
        )

    source = JobSource(
        kind=payload.kind,
        name=payload.name,
        config=payload.config,
        enabled=payload.enabled,
        rate_limit_per_minute=payload.rate_limit_per_minute,
    )
    session.add(source)
    session.add(
        AuditEvent(
            action="source.created",
            subject_type="job_source",
            subject_id=str(source.id),
            payload={"kind": payload.kind, "name": payload.name},
        )
    )
    await session.commit()
    return source


@router.patch("/sources/{source_id}", response_model=SourceRead)
async def update_source(
    source_id: uuid.UUID, payload: SourceUpdate, session: SessionDep
) -> JobSource:
    source = await session.get(JobSource, source_id)
    if source is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="source not found")

    changes = payload.model_dump(exclude_unset=True, exclude={"reset_failures", "reset_cursor"})
    if "config" in changes:
        try:
            build_source(source.kind, changes["config"])
        except SourceConfigError as exc:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc

    for name, value in changes.items():
        setattr(source, name, value)
    if payload.reset_failures:
        source.consecutive_failures = 0
        source.paused_until = None
        source.last_error = None
    if payload.reset_cursor:
        source.cursor = None

    await session.commit()
    return source


@router.delete("/sources/{source_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_source(source_id: uuid.UUID, session: SessionDep) -> None:
    source = await session.get(JobSource, source_id)
    if source is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="source not found")
    await session.delete(source)
    await session.commit()


# --- discovery --------------------------------------------------------------


@router.post("/discovery/run", response_model=DiscoveryReportRead)
async def run_discovery(
    session: SessionDep, source_id: uuid.UUID | None = None
) -> service.DiscoveryReport:
    """Run discovery now.

    Returns a per-source report rather than failing on the first bad board, so
    a partial run is visible instead of looking like a total failure.
    """
    return await service.run_discovery(session, source_id=source_id)


# --- jobs -------------------------------------------------------------------


@router.get("/jobs", response_model=JobPage)
async def list_jobs(
    session: SessionDep,
    q: str | None = None,
    company: str | None = None,
    source_id: uuid.UUID | None = None,
    seniority: Seniority | None = None,
    remote_type: RemoteType | None = None,
    country: str | None = None,
    include_duplicates: bool = False,
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
) -> JobPage:
    query = select(Job)
    if q:
        pattern = f"%{q.lower()}%"
        query = query.where(
            func.lower(Job.title).like(pattern) | func.lower(Job.description).like(pattern)
        )
    if company:
        query = query.where(func.lower(Job.company) == company.lower())
    if source_id:
        query = query.where(Job.source_id == source_id)
    if seniority:
        query = query.where(Job.seniority == seniority)
    if remote_type:
        query = query.where(Job.remote_type == remote_type)
    if country:
        query = query.where(func.lower(Job.country) == country.lower())
    if not include_duplicates:
        # Linked possible duplicates are hidden by default; the review queue
        # should not show the same role three times.
        query = query.where(Job.possible_duplicate_of.is_(None))

    total = await session.scalar(select(func.count()).select_from(query.subquery())) or 0
    result = await session.execute(
        query.order_by(Job.posted_at.desc().nullslast(), Job.fetched_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return JobPage(
        items=[JobRead.model_validate(job) for job in result.scalars()],
        total=total,
        limit=limit,
        offset=offset,
    )


@router.get("/jobs/{job_id}", response_model=JobDetail)
async def read_job(job_id: uuid.UUID, session: SessionDep) -> JobDetail:
    job = await session.get(Job, job_id)
    if job is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="job not found")

    snapshots = await session.execute(
        select(JobRawSnapshot)
        .where(JobRawSnapshot.job_id == job.id)
        .order_by(JobRawSnapshot.fetched_at.desc())
    )
    # Built field by field rather than with model_validate: JobDetail.snapshots
    # shadows the ORM relationship of the same name, and validating from
    # attributes would lazy-load it inside the request, which async SQLAlchemy
    # cannot do.
    return JobDetail(
        **JobRead.model_validate(job).model_dump(),
        description=job.description,
        snapshots=[SnapshotRead.model_validate(row) for row in snapshots.scalars()],
    )


@router.get("/jobs/{job_id}/snapshots/{snapshot_id}")
async def read_snapshot(
    job_id: uuid.UUID, snapshot_id: uuid.UUID, session: SessionDep
) -> dict[str, Any]:
    """The exact payload a board returned.

    Plan Phase 2 acceptance: every normalised field must be traceable to what
    was fetched.
    """
    snapshot = await session.get(JobRawSnapshot, snapshot_id)
    if snapshot is None or snapshot.job_id != job_id:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="snapshot not found")
    return {
        "id": str(snapshot.id),
        "source_url": snapshot.source_url,
        "fetched_at": snapshot.fetched_at.isoformat(),
        "content_hash": snapshot.content_hash,
        "payload": snapshot.payload,
    }
