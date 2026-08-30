"""Background actors.

Actors return ``None`` deliberately: the Results middleware is not installed, so
a return value would be silently discarded. Anything worth keeping is persisted
by the work itself.
"""

from __future__ import annotations

import asyncio
from datetime import UTC, datetime
from uuid import UUID

import dramatiq
from job_agent_discovery import run_discovery
from job_agent_domain.db import session_scope
from job_agent_observability import get_logger

from job_agent_worker.broker import broker  # noqa: F401 - import registers the broker

log = get_logger("worker")


@dramatiq.actor(max_retries=3, time_limit=60_000)
def heartbeat() -> None:
    """Proves the broker round-trip works."""
    log.info("worker_heartbeat", at=datetime.now(UTC).isoformat())


@dramatiq.actor(max_retries=0, time_limit=1_800_000)
def discover(source_id: str | None = None) -> None:
    """Run discovery over every enabled source, or one of them.

    ``max_retries=0``: the pipeline already isolates and backs off per source,
    so a dramatiq-level retry would re-run the sources that succeeded.
    """

    async def _run() -> None:
        async with session_scope() as session:
            report = await run_discovery(session, source_id=UUID(source_id) if source_id else None)
        for result in report.results:
            log.info(
                "discovery_source_finished",
                source=result.source_name,
                kind=result.kind,
                fetched=result.fetched,
                created=result.created,
                updated=result.updated,
                duplicates_linked=result.duplicates_linked,
                skipped=result.skipped,
                error=result.error,
            )
        log.info(
            "discovery_finished",
            sources=len(report.results),
            created=report.total_created,
            failed=len(report.failed_sources),
        )

    asyncio.run(_run())
