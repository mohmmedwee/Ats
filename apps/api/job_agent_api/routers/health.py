"""Liveness and readiness."""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Response, status
from job_agent_domain.db import get_sessionmaker
from job_agent_domain.settings import get_settings
from redis.asyncio import Redis
from sqlalchemy import text

router = APIRouter(tags=["health"])


@router.get("/health")
async def health() -> dict[str, str]:
    """Liveness only: says the process is up, checks no dependencies."""
    return {"status": "ok"}


@router.get("/ready")
async def ready(response: Response) -> dict[str, Any]:
    """Readiness: the API is only ready if its dependencies answer."""
    settings = get_settings()
    checks: dict[str, str] = {}

    try:
        async with get_sessionmaker()() as session:
            await session.execute(text("SELECT 1"))
        checks["database"] = "ok"
    except Exception as exc:
        checks["database"] = f"error: {exc.__class__.__name__}"

    redis: Redis | None = None
    try:
        redis = Redis.from_url(str(settings.redis_url))
        await redis.ping()
        checks["redis"] = "ok"
    except Exception as exc:
        checks["redis"] = f"error: {exc.__class__.__name__}"
    finally:
        if redis is not None:
            await redis.aclose()

    ok = all(value == "ok" for value in checks.values())
    if not ok:
        response.status_code = status.HTTP_503_SERVICE_UNAVAILABLE
    return {"status": "ok" if ok else "degraded", "checks": checks}
