"""Shared fixtures.

Tests never talk to a model provider or an employer. The AI provider is the
deterministic fake, and no test in this suite performs an external action.
"""

from __future__ import annotations

import os
import uuid
from collections.abc import AsyncIterator, Iterator

import pytest

os.environ.setdefault("ENV", "test")
os.environ.setdefault("AI_PROVIDER", "fake")
os.environ.setdefault("LOG_LEVEL", "WARNING")


@pytest.fixture(autouse=True)
def _reset_settings_cache() -> Iterator[None]:
    from job_agent_domain.settings import get_settings

    get_settings.cache_clear()
    yield
    get_settings.cache_clear()


@pytest.fixture
def settings():  # type: ignore[no-untyped-def]
    from job_agent_domain.settings import get_settings

    return get_settings()


@pytest.fixture
def fake_provider():  # type: ignore[no-untyped-def]
    from job_agent_ai import FakeProvider

    return FakeProvider()


@pytest.fixture
def user_id() -> uuid.UUID:
    return uuid.UUID("11111111-1111-1111-1111-111111111111")


@pytest.fixture
def tool_context(user_id: uuid.UUID):  # type: ignore[no-untyped-def]
    from job_agent_chat.tools import ToolContext

    return ToolContext(
        user_id=user_id,
        thread_id=uuid.UUID("22222222-2222-2222-2222-222222222222"),
        message_id=uuid.UUID("33333333-3333-3333-3333-333333333333"),
    )


@pytest.fixture
async def api_client() -> AsyncIterator[object]:
    import httpx
    from job_agent_api.main import create_app

    app = create_app()
    transport = httpx.ASGITransport(app=app)
    async with httpx.AsyncClient(transport=transport, base_url="http://test") as client:
        yield client
