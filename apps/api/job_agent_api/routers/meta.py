"""Policy and capability introspection.

The UI reads this to decide what to render, and the endpoint doubles as a
human-checkable statement of what the deployment will and will not do.
"""

from __future__ import annotations

from typing import Annotated, Any

from fastapi import APIRouter, Depends
from job_agent_chat.tools import EXTERNAL_ACTION_LINKS, ToolRegistry
from job_agent_domain.enums import AutonomyLevel, ToolTier
from job_agent_domain.settings import Settings

from job_agent_api.dependencies import get_app_settings, get_tool_registry

router = APIRouter(prefix="/api/v1", tags=["meta"])


@router.get("/policy")
async def policy(settings: Annotated[Settings, Depends(get_app_settings)]) -> dict[str, Any]:
    return {
        "autonomy_level": int(settings.autonomy_level),
        "autonomy_name": AutonomyLevel(settings.autonomy_level).name.lower(),
        "auto_submit_enabled": settings.autonomy_level >= AutonomyLevel.GUARDED_AUTO_SUBMIT,
        "max_applications_per_day": settings.max_applications_per_day,
        "discovery_cron": settings.discovery_cron,
        "discovery_timezone": settings.discovery_timezone,
        "chat": {
            "daily_token_budget": settings.chat_daily_token_budget,
            "max_tool_calls_per_turn": settings.chat_max_tool_calls_per_turn,
            "confirmation_ttl_seconds": settings.chat_confirmation_ttl_seconds,
        },
    }


@router.get("/chat/tools")
async def chat_tools(
    registry: Annotated[ToolRegistry, Depends(get_tool_registry)],
) -> dict[str, Any]:
    """Every tool chat can call, with its tier, plus the actions it cannot."""
    tools = []
    for name in registry.names():
        descriptor = registry.get(name)
        assert descriptor is not None
        tools.append(
            {
                "name": descriptor.name,
                "tier": descriptor.tier.value,
                "description": descriptor.description,
            }
        )
    return {
        "tools": tools,
        "tiers": {tier.name.lower(): tier.value for tier in ToolTier},
        "external_actions": [
            {"name": name, "deep_link": link, "callable_from_chat": False}
            for name, link in sorted(EXTERNAL_ACTION_LINKS.items())
        ],
    }
