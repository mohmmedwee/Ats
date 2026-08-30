"""Running discovery across sources.

Two properties matter more than throughput here:

* One failing board must not take the run down. Every source is isolated, and a
  source that keeps failing backs off instead of being hammered.
* Re-running discovery must not create duplicates. That is enforced by the
  unique ``(source_id, external_id)`` constraint and, across sources, by the
  rules in ``job_agent_connectors.dedup``.
"""

from __future__ import annotations

import uuid
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import UTC, datetime, timedelta
from typing import Any, cast

from job_agent_chat.injection import scan
from job_agent_connectors.base import DiscoveryBatch, RawJob
from job_agent_connectors.base import JobSource as Connector
from job_agent_connectors.dedup import find_duplicate
from job_agent_connectors.http import SourceFetchError
from job_agent_connectors.normalize import NormalizedJob, fold
from job_agent_connectors.registry import build_source
from job_agent_domain.models import AuditEvent, Company, Job, JobRawSnapshot, JobSource
from job_agent_observability import get_logger
from pydantic import BaseModel
from sqlalchemy import or_, select
from sqlalchemy.ext.asyncio import AsyncSession

#: How a source row becomes a connector. Injectable so tests can drive the
#: pipeline with a stub instead of reaching a real job board.
SourceFactory = Callable[[str, dict[str, Any]], Connector]

log = get_logger("discovery")

#: Backoff after repeated failures, indexed by consecutive failure count.
BACKOFF_MINUTES = (0, 5, 15, 60, 240)
MAX_BACKOFF_MINUTES = 720


class SourceResult(BaseModel):
    source_id: uuid.UUID
    source_name: str
    kind: str
    fetched: int = 0
    created: int = 0
    updated: int = 0
    duplicates_linked: int = 0
    injection_flagged: int = 0
    skipped: bool = False
    error: str | None = None

    @property
    def ok(self) -> bool:
        return self.error is None


class DiscoveryReport(BaseModel):
    started_at: datetime
    finished_at: datetime
    results: list[SourceResult] = []

    @property
    def total_created(self) -> int:
        return sum(result.created for result in self.results)

    @property
    def failed_sources(self) -> list[SourceResult]:
        return [result for result in self.results if not result.ok]


@dataclass(slots=True)
class _Counters:
    created: int = 0
    updated: int = 0
    duplicates: int = 0
    flagged: int = 0
    snapshots: list[JobRawSnapshot] = field(default_factory=list)


def backoff_until(consecutive_failures: int, now: datetime) -> datetime | None:
    """How long to leave a failing source alone.

    A board that is down for maintenance should not be retried every run; a
    board that failed once should not be punished for it.
    """
    if consecutive_failures <= 0:
        return None
    index = min(consecutive_failures, len(BACKOFF_MINUTES) - 1)
    minutes = BACKOFF_MINUTES[index]
    if consecutive_failures >= len(BACKOFF_MINUTES):
        minutes = min(MAX_BACKOFF_MINUTES, BACKOFF_MINUTES[-1] * (consecutive_failures - 2))
    return now + timedelta(minutes=minutes) if minutes else None


async def _get_or_create_company(session: AsyncSession, name: str) -> Company:
    normalized = fold(name)
    result = await session.execute(select(Company).where(Company.normalized_name == normalized))
    company = result.scalar_one_or_none()
    if company is None:
        company = Company(name=name, normalized_name=normalized)
        session.add(company)
        await session.flush()
    return company


async def _record_snapshot(
    session: AsyncSession, source: JobSource, raw: RawJob
) -> JobRawSnapshot | None:
    """Store the raw payload unless this exact content is already recorded."""
    existing = await session.execute(
        select(JobRawSnapshot).where(
            JobRawSnapshot.source_id == source.id,
            JobRawSnapshot.external_id == raw.external_id,
            JobRawSnapshot.content_hash == raw.content_hash,
        )
    )
    if existing.scalar_one_or_none() is not None:
        return None

    snapshot = JobRawSnapshot(
        source_id=source.id,
        external_id=raw.external_id,
        source_url=raw.source_url,
        fetched_at=raw.fetched_at,
        content_hash=raw.content_hash,
        payload=raw.payload,
    )
    session.add(snapshot)
    return snapshot


async def _duplicate_candidates(
    session: AsyncSession, source: JobSource, job: NormalizedJob
) -> list[Job]:
    """Fetch only the rows any dedup rule could match, not the whole table."""
    result = await session.execute(
        select(Job).where(
            or_(
                (Job.source_id == source.id) & (Job.external_id == job.external_id),
                Job.canonical_url == job.canonical_url,
                Job.fingerprint == job.fingerprint,
                (Job.company == job.company) & (Job.normalized_title == job.normalized_title),
            )
        )
    )
    return list(result.scalars())


def _apply(job: Job, normalized: NormalizedJob, source: JobSource, raw: RawJob) -> None:
    job.source_id = source.id
    job.external_id = normalized.external_id
    job.company = normalized.company
    job.title = normalized.title
    job.normalized_title = normalized.normalized_title
    job.seniority = normalized.seniority
    job.description = normalized.description
    job.application_url = normalized.application_url
    job.canonical_url = normalized.canonical_url
    job.location = normalized.location
    job.city = normalized.city
    job.country = normalized.country
    job.remote_type = normalized.remote_type
    job.employment_type = normalized.employment_type
    job.compensation = normalized.compensation
    job.required_skills = normalized.required_skills
    job.preferred_skills = normalized.preferred_skills
    job.responsibilities = normalized.responsibilities
    job.visa_sponsorship = normalized.visa_sponsorship
    job.content_hash = normalized.content_hash
    job.fingerprint = normalized.fingerprint
    job.posted_at = normalized.posted_at
    job.closes_at = normalized.closes_at
    job.fetched_at = raw.fetched_at


async def _ingest_batch(
    session: AsyncSession,
    source: JobSource,
    connector: Connector,
    batch: DiscoveryBatch,
) -> _Counters:
    counters = _Counters()

    for raw in batch.jobs:
        normalized = connector.normalize(raw)

        # The snapshot is written whether or not the job is new, so a
        # normalisation bug can always be traced back to what the board sent.
        # One row per distinct content: re-running discovery over unchanged
        # postings must not grow the table without bound.
        snapshot = await _record_snapshot(session, source, raw)

        candidates = await _duplicate_candidates(session, source, normalized)
        match = find_duplicate(normalized, candidates, source_id=source.id)

        if match is not None and match.should_merge:
            existing: Job = cast(Job, match.job)
            if snapshot is not None:
                snapshot.job_id = existing.id
            if existing.content_hash != normalized.content_hash:
                _apply(existing, normalized, source, raw)
                counters.updated += 1
            continue

        company = await _get_or_create_company(session, normalized.company)
        job = Job(
            source_id=source.id,
            external_id=normalized.external_id,
            company=normalized.company,
            company_id=company.id,
            title=normalized.title,
            description=normalized.description,
            application_url=normalized.application_url,
            content_hash=normalized.content_hash,
        )
        _apply(job, normalized, source, raw)

        # A posting is untrusted text. Flagging it here means the chat agent and
        # the review queue can both show which employer tried something.
        result = scan(normalized.description)
        if result.suspected:
            job.injection_flagged = True
            job.injection_signals = list(result.signals)
            counters.flagged += 1

        if match is not None:
            # Not confident enough to merge: link instead, and let a person decide.
            job.possible_duplicate_of = cast(Job, match.job).id
            job.duplicate_reason = match.reason
            job.duplicate_confidence = match.confidence
            counters.duplicates += 1

        session.add(job)
        await session.flush()
        if snapshot is not None:
            snapshot.job_id = job.id
        counters.created += 1

    return counters


async def run_source(
    session: AsyncSession,
    source: JobSource,
    *,
    source_factory: SourceFactory = build_source,
) -> SourceResult:
    """Run one source. Never raises: a failure is data, not an exception."""
    result = SourceResult(source_id=source.id, source_name=source.name, kind=source.kind)
    now = datetime.now(UTC)

    if not source.enabled:
        result.skipped = True
        return result
    if source.paused_until and source.paused_until > now:
        result.skipped = True
        result.error = f"backing off until {source.paused_until.isoformat()}"
        return result

    try:
        connector = source_factory(
            source.kind,
            {**source.config, "rate_limit_per_minute": source.rate_limit_per_minute},
        )
        batch = await connector.discover(source.cursor)
        result.fetched = len(batch.jobs)

        counters = await _ingest_batch(session, source, connector, batch)
        result.created = counters.created
        result.updated = counters.updated
        result.duplicates_linked = counters.duplicates
        result.injection_flagged = counters.flagged

        source.cursor = batch.next_cursor
        source.last_run_at = now
        source.last_success_at = now
        source.last_error = None
        source.consecutive_failures = 0
        source.paused_until = None

    except (SourceFetchError, ValueError, KeyError, TypeError) as exc:
        # Caught deliberately broadly: one board returning something unexpected
        # must not stop the others in the same run.
        source.last_run_at = now
        source.last_error = str(exc)
        source.consecutive_failures += 1
        source.paused_until = backoff_until(source.consecutive_failures, now)
        result.error = str(exc)
        log.warning(
            "source_failed",
            source=source.name,
            kind=source.kind,
            error=str(exc),
            consecutive_failures=source.consecutive_failures,
        )

    session.add(
        AuditEvent(
            action="discovery.source_run",
            subject_type="job_source",
            subject_id=str(source.id),
            payload=result.model_dump(mode="json"),
        )
    )
    return result


async def run_discovery(
    session: AsyncSession,
    *,
    source_id: uuid.UUID | None = None,
    source_factory: SourceFactory = build_source,
) -> DiscoveryReport:
    started = datetime.now(UTC)

    query = select(JobSource).order_by(JobSource.name)
    if source_id is not None:
        query = query.where(JobSource.id == source_id)
    sources = list((await session.execute(query)).scalars())

    results: list[SourceResult] = []
    for source in sources:
        results.append(await run_source(session, source, source_factory=source_factory))
        # Commit per source so one source's failure cannot roll back another's
        # successfully ingested jobs.
        await session.commit()

    return DiscoveryReport(started_at=started, finished_at=datetime.now(UTC), results=results)
