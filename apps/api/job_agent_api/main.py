"""FastAPI application factory."""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from job_agent_domain.db import dispose_engine
from job_agent_domain.settings import Settings, get_settings
from job_agent_observability import configure_logging, get_logger

from job_agent_api.middleware.idempotency import IdempotencyMiddleware
from job_agent_api.middleware.request_context import RequestContextMiddleware
from job_agent_api.routers import health, meta

log = get_logger("api")


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    settings: Settings = app.state.settings
    log.info("api_starting", env=settings.env, autonomy_level=int(settings.autonomy_level))
    try:
        yield
    finally:
        await dispose_engine()
        log.info("api_stopped")


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or get_settings()
    settings.validate_for_production()
    configure_logging(level=settings.log_level, json_output=settings.env != "development")

    app = FastAPI(
        title="Job Agent API",
        version="0.1.0",
        summary="Discovery, matching, application preparation, and chat.",
        lifespan=lifespan,
    )
    app.state.settings = settings

    app.add_middleware(
        CORSMiddleware,
        allow_origins=[settings.web_origin],
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
        expose_headers=["X-Request-ID"],
    )
    app.add_middleware(IdempotencyMiddleware)
    app.add_middleware(RequestContextMiddleware)

    app.include_router(health.router)
    app.include_router(meta.router)
    return app


app = create_app()
