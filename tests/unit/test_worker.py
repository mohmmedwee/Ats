"""Broker and scheduler configuration."""

from __future__ import annotations

from dramatiq.middleware import Pipelines, Retries


def test_broker_middleware_has_no_duplicates() -> None:
    """Appending to the default stack silently doubled TimeLimit and Retries."""
    from job_agent_worker.broker import build_broker

    broker = build_broker()
    names = [type(m).__name__ for m in broker.middleware]
    assert len(names) == len(set(names))


def test_broker_keeps_pipelines_and_uses_configured_retries() -> None:
    from job_agent_worker.broker import build_broker

    broker = build_broker()
    types = {type(m) for m in broker.middleware}
    assert Pipelines in types

    retries = next(m for m in broker.middleware if isinstance(m, Retries))
    assert retries.max_retries == 3


def test_schedule_is_evaluated_in_the_configured_timezone() -> None:
    """Plan Phase 6 acceptance: the schedule is timezone-aware."""
    from job_agent_domain.settings import get_settings
    from job_agent_worker.scheduler import build_scheduler

    settings = get_settings()
    scheduler = build_scheduler()
    job = scheduler.get_job("daily_discovery")
    assert job is not None
    assert str(job.trigger.timezone) == settings.discovery_timezone
