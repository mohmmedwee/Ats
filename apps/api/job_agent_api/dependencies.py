"""Shared FastAPI dependencies."""

from __future__ import annotations

from collections.abc import AsyncIterator
from functools import lru_cache

from job_agent_chat.tools import ToolRegistry
from job_agent_domain.db import get_sessionmaker
from job_agent_domain.settings import Settings, get_settings
from sqlalchemy.ext.asyncio import AsyncSession


async def get_session() -> AsyncIterator[AsyncSession]:
    async with get_sessionmaker()() as session:
        yield session


def get_app_settings() -> Settings:
    return get_settings()


@lru_cache(maxsize=1)
def get_tool_registry() -> ToolRegistry:
    """The chat tool registry.

    Phase 0 builds it empty of handlers but with the tier rules live, so the
    guarantee in plan section 7.8 is enforced and testable before Phase 8 wires
    real services in.
    """
    settings = get_settings()
    return ToolRegistry(confirmation_ttl_seconds=settings.chat_confirmation_ttl_seconds)
