"""Background actors.

Phase 0 ships the plumbing and a heartbeat. Discovery, scoring, and pack
generation actors arrive with their phases; each one persists its input, output,
status, attempts, and error before the next node runs (plan section 6).
"""

from __future__ import annotations

from datetime import UTC, datetime

import dramatiq
from job_agent_observability import get_logger

from job_agent_worker.broker import broker  # noqa: F401 - import registers the broker

log = get_logger("worker")


@dramatiq.actor(max_retries=3, time_limit=60_000)
def heartbeat() -> None:
    """Proves the broker round-trip works.

    Actors return ``None`` deliberately: the Results middleware is not
    installed, so a return value would be silently discarded. Anything worth
    keeping is persisted by the actor itself.
    """
    log.info("worker_heartbeat", at=datetime.now(UTC).isoformat())


@dramatiq.actor(max_retries=3, time_limit=600_000)
def run_discovery(source_id: str | None = None) -> None:
    """Placeholder for Phase 2.

    A failing source must not stop the others, so the real implementation fans
    out one message per source rather than looping in a single actor.
    """
    log.info("discovery_requested", source_id=source_id, implemented=False)
