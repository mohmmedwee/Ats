"""Recurring schedule.

Timezone-aware by requirement (plan Phase 6 acceptance): the cron expression is
evaluated in the user's configured timezone, not the container's.
"""

from __future__ import annotations

import asyncio

from apscheduler.schedulers.asyncio import AsyncIOScheduler
from apscheduler.triggers.cron import CronTrigger
from job_agent_domain.settings import get_settings
from job_agent_observability import configure_logging, get_logger

from job_agent_worker.actors import discover

log = get_logger("scheduler")


def build_scheduler() -> AsyncIOScheduler:
    settings = get_settings()
    scheduler = AsyncIOScheduler(timezone=settings.discovery_timezone)
    scheduler.add_job(
        discover.send,
        CronTrigger.from_crontab(settings.discovery_cron, timezone=settings.discovery_timezone),
        id="daily_discovery",
        replace_existing=True,
        misfire_grace_time=3600,
    )
    return scheduler


def main() -> None:
    settings = get_settings()
    configure_logging(level=settings.log_level, json_output=settings.env != "development")
    scheduler = build_scheduler()
    scheduler.start()
    log.info("scheduler_started", cron=settings.discovery_cron, tz=settings.discovery_timezone)
    try:
        asyncio.get_event_loop().run_forever()
    except (KeyboardInterrupt, SystemExit):
        scheduler.shutdown()
        log.info("scheduler_stopped")


if __name__ == "__main__":
    main()
