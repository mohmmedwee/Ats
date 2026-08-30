"""Dramatiq broker wiring."""

from __future__ import annotations

import dramatiq
from dramatiq.brokers.redis import RedisBroker
from dramatiq.middleware import Retries, default_middleware
from job_agent_domain.settings import get_settings


def build_broker() -> RedisBroker:
    """Build the broker with an explicit middleware stack.

    ``RedisBroker`` installs dramatiq's default stack unless it is given one, so
    the list is passed to the constructor rather than appended afterwards:
    appending re-adds middleware that is already there, and a duplicated
    ``TimeLimit`` or ``Retries`` changes behaviour in ways that are hard to see.

    The stack is the default one with ``Retries`` reconfigured, which keeps
    ``Pipelines`` and the rest in their usual order. Retries here cover
    transport-level failures only. Anything that touches an employer is retried
    by the orchestrator under its idempotency rules (plan section 7.6), never
    blindly at this layer.
    """
    settings = get_settings()
    middleware = [Retries(max_retries=3) if cls is Retries else cls() for cls in default_middleware]
    # dramatiq ships no type information; the call is checked by its own tests.
    return RedisBroker(  # type: ignore[no-untyped-call]
        url=str(settings.redis_url), middleware=middleware
    )


broker = build_broker()
dramatiq.set_broker(broker)
