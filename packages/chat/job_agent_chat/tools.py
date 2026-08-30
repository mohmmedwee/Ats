"""Chat tool registry with server-side tier enforcement.

This module is the load-bearing part of ``job-agent-plan.md`` section 7.8. The
rules it enforces:

* A ``ToolTier.EXTERNAL`` tool cannot be registered. Chat therefore has no code
  path to submission or policy changes, rather than a prompt asking it not to.
* Arguments are validated against a Pydantic model before dispatch. Invalid
  arguments produce a tool error the model can read and retry, never a partial
  execution.
* The calling user is injected by the registry. Any ``user_id`` the model
  proposes is discarded.
* ``ToolTier.PREPARE`` calls do not execute on first sight. They return a
  confirmation card bound to a hash of the exact arguments shown to the user;
  changed arguments invalidate it.
* Every dispatch derives a deterministic idempotency key from
  ``(thread_id, message_id, tool_name, args_hash)``.
"""

from __future__ import annotations

import hashlib
import json
import uuid
from collections.abc import Awaitable, Callable, Iterable
from dataclasses import dataclass, field
from datetime import UTC, datetime, timedelta
from typing import Any

from job_agent_ai.types import ToolCallRequest, ToolSpec
from job_agent_domain.enums import AutonomyLevel, ToolTier
from pydantic import BaseModel, ValidationError

from job_agent_chat.errors import ExternalTierNotCallableError

#: Keys the model is never allowed to set; the registry owns them.
RESERVED_ARG_KEYS = frozenset({"user_id", "profile_id", "tier", "idempotency_key"})

#: Where the UI handles actions chat cannot perform (plan 7.8 T2 handling).
EXTERNAL_ACTION_LINKS: dict[str, str] = {
    "start_form": "/applications/{application_id}/form",
    "submit_application": "/applications/{application_id}/review",
    "add_source": "/sources/new",
    "set_autonomy_level": "/policies",
}


def canonical_args_hash(arguments: dict[str, Any]) -> str:
    """Stable hash of arguments, independent of key order."""
    canonical = json.dumps(arguments, sort_keys=True, separators=(",", ":"), default=str)
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def idempotency_key(
    thread_id: uuid.UUID, message_id: uuid.UUID, tool_name: str, args_hash: str
) -> str:
    material = f"{thread_id}:{message_id}:{tool_name}:{args_hash}"
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


@dataclass(frozen=True, slots=True)
class ToolContext:
    """Everything a handler is allowed to know about the caller."""

    user_id: uuid.UUID
    thread_id: uuid.UUID
    message_id: uuid.UUID
    autonomy_level: AutonomyLevel = AutonomyLevel.ASSISTED_APPLY
    #: Entity the chat panel is docked to, e.g. {"job_id": "..."}.
    context: dict[str, Any] = field(default_factory=dict)


class Confirmation(BaseModel):
    """A user's in-thread approval of one T1 call."""

    tool_name: str
    args_hash: str
    confirmed_at: datetime


class ToolResult(BaseModel):
    """What the registry hands back. Never raises into the model loop."""

    tool_name: str
    tier: ToolTier
    status: str
    data: dict[str, Any] | None = None
    error: str | None = None
    args_hash: str | None = None
    idempotency_key: str | None = None
    expires_at: datetime | None = None
    deep_link: str | None = None

    @property
    def ok(self) -> bool:
        return self.status == "ok"


@dataclass(frozen=True, slots=True)
class ToolDescriptor[ArgsT: BaseModel]:
    name: str
    description: str
    tier: ToolTier
    args_model: type[ArgsT]
    handler: Callable[[ToolContext, ArgsT], Awaitable[dict[str, Any]]]

    def spec(self) -> ToolSpec:
        return ToolSpec(
            name=self.name,
            description=self.description,
            parameters=self.args_model.model_json_schema(),
        )


class ToolRegistry:
    """Holds the tools chat may call. There is no way to add an external one."""

    def __init__(self, *, confirmation_ttl_seconds: int = 900) -> None:
        self._tools: dict[str, ToolDescriptor[Any]] = {}
        self._confirmation_ttl = timedelta(seconds=confirmation_ttl_seconds)

    def register(self, descriptor: ToolDescriptor[Any]) -> None:
        if descriptor.tier is ToolTier.EXTERNAL:
            raise ExternalTierNotCallableError(
                f"{descriptor.name!r} is an external action; chat must deep-link to the UI "
                "gate instead of calling it"
            )
        if descriptor.name in self._tools:
            raise ValueError(f"tool already registered: {descriptor.name}")
        self._tools[descriptor.name] = descriptor

    def get(self, name: str) -> ToolDescriptor[Any] | None:
        return self._tools.get(name)

    def names(self) -> list[str]:
        return sorted(self._tools)

    def specs(
        self, *, autonomy_level: AutonomyLevel = AutonomyLevel.ASSISTED_APPLY
    ) -> list[ToolSpec]:
        """Tools advertised to the model.

        At autonomy level 0 the agent is a scout: it may look, not prepare.
        """
        allowed = self._allowed_tiers(autonomy_level)
        return [d.spec() for d in self._tools.values() if d.tier in allowed]

    @staticmethod
    def _allowed_tiers(autonomy_level: AutonomyLevel) -> frozenset[ToolTier]:
        if autonomy_level <= AutonomyLevel.SCOUT:
            return frozenset({ToolTier.READ})
        return frozenset({ToolTier.READ, ToolTier.PREPARE})

    @staticmethod
    def deep_link_for(name: str, arguments: dict[str, Any]) -> str | None:
        template = EXTERNAL_ACTION_LINKS.get(name)
        if template is None:
            return None
        try:
            return template.format(**arguments)
        except KeyError:
            # Missing an id is fine: send the user to the section, not a 404.
            return template.split("{", 1)[0].rstrip("/") or "/"

    async def dispatch(
        self,
        request: ToolCallRequest,
        context: ToolContext,
        *,
        confirmation: Confirmation | None = None,
        now: datetime | None = None,
    ) -> ToolResult:
        now = now or datetime.now(UTC)
        descriptor = self._tools.get(request.name)

        if descriptor is None:
            # Unknown names include every external action, so answer those with
            # the deep link rather than a bare "no such tool".
            link = self.deep_link_for(request.name, request.arguments)
            if link is not None:
                return ToolResult(
                    tool_name=request.name,
                    tier=ToolTier.EXTERNAL,
                    status="requires_ui",
                    error=(
                        f"{request.name} is an external action and cannot be performed from "
                        "chat. Open the linked screen to review and confirm it."
                    ),
                    deep_link=link,
                )
            return ToolResult(
                tool_name=request.name,
                tier=ToolTier.READ,
                status="error",
                error=f"unknown tool: {request.name}",
            )

        if descriptor.tier not in self._allowed_tiers(context.autonomy_level):
            return ToolResult(
                tool_name=descriptor.name,
                tier=descriptor.tier,
                status="error",
                error=(
                    f"{descriptor.name} requires autonomy level "
                    f"{AutonomyLevel.PREPARE.value} or higher; current level is "
                    f"{context.autonomy_level.value}"
                ),
            )

        arguments = {k: v for k, v in request.arguments.items() if k not in RESERVED_ARG_KEYS}
        try:
            parsed = descriptor.args_model.model_validate(arguments)
        except ValidationError as exc:
            return ToolResult(
                tool_name=descriptor.name,
                tier=descriptor.tier,
                status="invalid_arguments",
                error=exc.json(),
            )

        # Hash the parsed arguments, not the raw ones: what the user sees on the
        # confirmation card is the normalised set the handler will receive.
        normalised = parsed.model_dump(mode="json")
        args_hash = canonical_args_hash(normalised)
        key = idempotency_key(context.thread_id, context.message_id, descriptor.name, args_hash)

        if descriptor.tier is ToolTier.PREPARE:
            if confirmation is None:
                return ToolResult(
                    tool_name=descriptor.name,
                    tier=descriptor.tier,
                    status="confirmation_required",
                    data=normalised,
                    args_hash=args_hash,
                    idempotency_key=key,
                    expires_at=now + self._confirmation_ttl,
                )
            if confirmation.tool_name != descriptor.name or confirmation.args_hash != args_hash:
                return ToolResult(
                    tool_name=descriptor.name,
                    tier=descriptor.tier,
                    status="confirmation_mismatch",
                    error="the arguments changed since this action was confirmed",
                    args_hash=args_hash,
                )
            if confirmation.confirmed_at + self._confirmation_ttl < now:
                return ToolResult(
                    tool_name=descriptor.name,
                    tier=descriptor.tier,
                    status="confirmation_expired",
                    error="the confirmation expired; ask again to get a fresh one",
                    args_hash=args_hash,
                )

        try:
            data = await descriptor.handler(context, parsed)
        except Exception as exc:
            return ToolResult(
                tool_name=descriptor.name,
                tier=descriptor.tier,
                status="error",
                error=str(exc),
                args_hash=args_hash,
                idempotency_key=key,
            )

        return ToolResult(
            tool_name=descriptor.name,
            tier=descriptor.tier,
            status="ok",
            data=data,
            args_hash=args_hash,
            idempotency_key=key,
        )


def build_registry(
    descriptors: Iterable[ToolDescriptor[Any]], *, confirmation_ttl_seconds: int = 900
) -> ToolRegistry:
    registry = ToolRegistry(confirmation_ttl_seconds=confirmation_ttl_seconds)
    for descriptor in descriptors:
        registry.register(descriptor)
    return registry
