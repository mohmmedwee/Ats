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
