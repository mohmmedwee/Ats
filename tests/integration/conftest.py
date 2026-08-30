"""Integration fixtures.

pytest-asyncio gives each test its own event loop, so the cached engine has to
be disposed inside that loop; otherwise asyncpg connections outlive the loop
that created them.
"""

from __future__ import annotations

from collections.abc import AsyncIterator

import pytest


@pytest.fixture(autouse=True)
async def _fresh_engine() -> AsyncIterator[None]:
    from job_agent_domain.db import dispose_engine, reset_engine_cache

    reset_engine_cache()
    yield
    await dispose_engine()


@pytest.fixture
async def clean_db() -> AsyncIterator[None]:
    """Truncate the tables Phase 1 touches.

    Integration tests share one database, so each starts from a known state
    rather than depending on the order they happen to run in.
    """
    from job_agent_domain.db import get_sessionmaker
    from sqlalchemy import text

    tables = (
        "chat_tool_calls, chat_messages, chat_threads, audit_events, answer_bank, "
        "candidate_facts, candidate_profiles, resume_files, applications, job_matches, "
        "job_raw_snapshots, jobs, companies, job_sources, users"
    )
    async with get_sessionmaker()() as session:
        await session.execute(text(f"TRUNCATE {tables} RESTART IDENTITY CASCADE"))
        await session.commit()
    yield


@pytest.fixture
async def profile_client(clean_db: None, tmp_path) -> AsyncIterator[tuple[object, object]]:  # type: ignore[no-untyped-def]
    """API client whose model provider is a scriptable fake and whose uploads
    land in a temporary directory."""
    import httpx
    from job_agent_ai import FakeProvider
    from job_agent_api.dependencies import get_ai_provider
    from job_agent_api.main import create_app
    from job_agent_domain.settings import get_settings

    settings = get_settings()
    settings.storage_dir = tmp_path / "storage"

    provider = FakeProvider()
    app = create_app(settings)
    app.dependency_overrides[get_ai_provider] = lambda: provider

    transport = httpx.ASGITransport(app=app)
    async with httpx.AsyncClient(
        transport=transport, base_url="http://test", headers={"Idempotency-Key": "test-key"}
    ) as client:
        yield client, provider
