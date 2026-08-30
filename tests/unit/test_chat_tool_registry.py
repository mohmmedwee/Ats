"""The chat agent's safety guarantees, as tests.

These correspond to the Phase 8 acceptance criteria in ``job-agent-plan.md``.
"""

from __future__ import annotations

import uuid
from datetime import UTC, datetime, timedelta
from typing import Any

import pytest
from job_agent_ai.types import ToolCallRequest
from job_agent_chat.builtin_tools import JobIdArgs, default_tools
from job_agent_chat.errors import ExternalTierNotCallableError
from job_agent_chat.tools import (
    Confirmation,
    ToolContext,
    ToolDescriptor,
    ToolRegistry,
    build_registry,
    canonical_args_hash,
    idempotency_key,
)
from job_agent_domain.enums import AutonomyLevel, ToolTier
from pydantic import BaseModel


class EchoArgs(BaseModel):
    value: str


async def _echo(ctx: ToolContext, args: EchoArgs) -> dict[str, Any]:
    return {"value": args.value, "user_id": str(ctx.user_id)}


def _read_tool(name: str = "echo") -> ToolDescriptor[EchoArgs]:
    return ToolDescriptor(
        name=name,
        description="echo",
        tier=ToolTier.READ,
        args_model=EchoArgs,
        handler=_echo,
    )


def _prepare_tool(name: str = "prepare_echo") -> ToolDescriptor[EchoArgs]:
    return ToolDescriptor(
        name=name,
        description="echo, but a mutation",
        tier=ToolTier.PREPARE,
        args_model=EchoArgs,
        handler=_echo,
    )


class StubServices:
    """Records calls so tests can assert a handler did or did not run."""

    def __init__(self) -> None:
        self.calls: list[str] = []

    async def _record(self, name: str) -> dict[str, Any]:
        self.calls.append(name)
        return {"ok": True}

    async def search_jobs(self, user_id: uuid.UUID, args: Any) -> dict[str, Any]:
        return await self._record("search_jobs")

    async def get_job(self, user_id: uuid.UUID, job_id: uuid.UUID) -> dict[str, Any]:
        return await self._record("get_job")

    async def get_match_explanation(self, user_id: uuid.UUID, job_id: uuid.UUID) -> dict[str, Any]:
        return await self._record("get_match_explanation")

    async def get_pipeline_summary(self, user_id: uuid.UUID) -> dict[str, Any]:
        return await self._record("get_pipeline_summary")

    async def get_application_status(
        self, user_id: uuid.UUID, application_id: uuid.UUID
    ) -> dict[str, Any]:
        return await self._record("get_application_status")

    async def shortlist_job(self, user_id: uuid.UUID, job_id: uuid.UUID) -> dict[str, Any]:
        return await self._record("shortlist_job")

    async def reject_job(self, user_id: uuid.UUID, job_id: uuid.UUID) -> dict[str, Any]:
        return await self._record("reject_job")

    async def generate_application_pack(
        self, user_id: uuid.UUID, job_id: uuid.UUID
    ) -> dict[str, Any]:
        return await self._record("generate_application_pack")

    async def draft_answer(self, user_id: uuid.UUID, args: Any) -> dict[str, Any]:
        return await self._record("draft_answer")

    async def update_preferences(self, user_id: uuid.UUID, args: Any) -> dict[str, Any]:
        return await self._record("update_preferences")


# --- the structural guarantee ----------------------------------------------


def test_external_tier_cannot_be_registered() -> None:
    registry = ToolRegistry()
    external = ToolDescriptor(
        name="submit_application",
        description="submit",
        tier=ToolTier.EXTERNAL,
        args_model=EchoArgs,
        handler=_echo,
    )
    with pytest.raises(ExternalTierNotCallableError):
        registry.register(external)
    assert "submit_application" not in registry.names()


def test_default_tool_set_contains_no_external_tool() -> None:
    """Phase 8 acceptance: the registry contains no T2 tool."""
    registry = build_registry(default_tools(StubServices()))
    for name in registry.names():
        descriptor = registry.get(name)
        assert descriptor is not None
        assert descriptor.tier is not ToolTier.EXTERNAL

    forbidden = {"submit_application", "start_form", "add_source", "set_autonomy_level"}
    assert forbidden.isdisjoint(registry.names())


async def test_external_action_returns_deep_link_not_execution(tool_context: ToolContext) -> None:
    registry = build_registry(default_tools(StubServices()))
    application_id = uuid.uuid4()
    result = await registry.dispatch(
        ToolCallRequest(
            id="c1",
            name="submit_application",
            arguments={"application_id": str(application_id)},
        ),
        tool_context,
    )
    assert result.status == "requires_ui"
    assert result.deep_link == f"/applications/{application_id}/review"
    assert result.data is None


# --- argument handling ------------------------------------------------------


async def test_invalid_arguments_return_error_not_execution(tool_context: ToolContext) -> None:
    services = StubServices()
    registry = build_registry(default_tools(services))
    result = await registry.dispatch(
        ToolCallRequest(id="c1", name="get_job", arguments={"job_id": "not-a-uuid"}),
        tool_context,
    )
    assert result.status == "invalid_arguments"
    assert services.calls == []


async def test_model_supplied_user_id_is_discarded(tool_context: ToolContext) -> None:
    registry = build_registry([_read_tool()])
    attacker_id = uuid.uuid4()
    result = await registry.dispatch(
        ToolCallRequest(
            id="c1", name="echo", arguments={"value": "hi", "user_id": str(attacker_id)}
        ),
        tool_context,
    )
    assert result.ok
    assert result.data is not None
    assert result.data["user_id"] == str(tool_context.user_id)


async def test_unknown_tool_is_an_error(tool_context: ToolContext) -> None:
    registry = build_registry([_read_tool()])
    result = await registry.dispatch(
        ToolCallRequest(id="c1", name="rm_rf", arguments={}), tool_context
    )
    assert result.status == "error"
    assert "unknown tool" in (result.error or "")


# --- tiers and confirmation -------------------------------------------------


async def test_read_tool_executes_without_confirmation(tool_context: ToolContext) -> None:
    registry = build_registry([_read_tool()])
    result = await registry.dispatch(
        ToolCallRequest(id="c1", name="echo", arguments={"value": "hi"}), tool_context
    )
    assert result.ok
    assert result.tier is ToolTier.READ


async def test_prepare_tool_requires_confirmation_first(tool_context: ToolContext) -> None:
    services = StubServices()
    registry = build_registry(default_tools(services))
    request = ToolCallRequest(
        id="c1", name="shortlist_job", arguments={"job_id": str(uuid.uuid4())}
    )

    first = await registry.dispatch(request, tool_context)
    assert first.status == "confirmation_required"
    assert first.args_hash is not None
    assert first.expires_at is not None
    assert services.calls == []

    confirmed = await registry.dispatch(
        request,
        tool_context,
        confirmation=Confirmation(
            tool_name="shortlist_job",
            args_hash=first.args_hash,
            confirmed_at=datetime.now(UTC),
        ),
    )
    assert confirmed.ok
    assert services.calls == ["shortlist_job"]


async def test_confirmation_is_bound_to_exact_arguments(tool_context: ToolContext) -> None:
    services = StubServices()
    registry = build_registry(default_tools(services))
    shown = ToolCallRequest(id="c1", name="reject_job", arguments={"job_id": str(uuid.uuid4())})
    first = await registry.dispatch(shown, tool_context)
    assert first.args_hash is not None

    swapped = ToolCallRequest(id="c2", name="reject_job", arguments={"job_id": str(uuid.uuid4())})
    result = await registry.dispatch(
        swapped,
        tool_context,
        confirmation=Confirmation(
            tool_name="reject_job", args_hash=first.args_hash, confirmed_at=datetime.now(UTC)
        ),
    )
    assert result.status == "confirmation_mismatch"
    assert services.calls == []


async def test_expired_confirmation_does_not_execute(tool_context: ToolContext) -> None:
    services = StubServices()
    registry = build_registry(default_tools(services), confirmation_ttl_seconds=60)
    request = ToolCallRequest(
        id="c1", name="shortlist_job", arguments={"job_id": str(uuid.uuid4())}
    )
    first = await registry.dispatch(request, tool_context)
    assert first.args_hash is not None

    result = await registry.dispatch(
        request,
        tool_context,
        confirmation=Confirmation(
            tool_name="shortlist_job",
            args_hash=first.args_hash,
            confirmed_at=datetime.now(UTC) - timedelta(minutes=5),
        ),
    )
    assert result.status == "confirmation_expired"
    assert services.calls == []


async def test_scout_autonomy_hides_and_blocks_prepare_tools(user_id: uuid.UUID) -> None:
    services = StubServices()
    registry = build_registry(default_tools(services))
    scout = ToolContext(
        user_id=user_id,
        thread_id=uuid.uuid4(),
        message_id=uuid.uuid4(),
        autonomy_level=AutonomyLevel.SCOUT,
    )

    advertised = {spec.name for spec in registry.specs(autonomy_level=AutonomyLevel.SCOUT)}
    assert "shortlist_job" not in advertised
    assert "search_jobs" in advertised

    result = await registry.dispatch(
        ToolCallRequest(id="c1", name="shortlist_job", arguments={"job_id": str(uuid.uuid4())}),
        scout,
    )
    assert result.status == "error"
    assert services.calls == []


# --- idempotency ------------------------------------------------------------


def test_args_hash_is_order_independent() -> None:
    assert canonical_args_hash({"a": 1, "b": 2}) == canonical_args_hash({"b": 2, "a": 1})


def test_idempotency_key_is_deterministic_and_scoped() -> None:
    thread = uuid.uuid4()
    message = uuid.uuid4()
    args_hash = canonical_args_hash({"job_id": "x"})

    first = idempotency_key(thread, message, "shortlist_job", args_hash)
    assert first == idempotency_key(thread, message, "shortlist_job", args_hash)
    assert first != idempotency_key(uuid.uuid4(), message, "shortlist_job", args_hash)
    assert first != idempotency_key(thread, message, "reject_job", args_hash)


async def test_same_call_in_same_turn_yields_same_idempotency_key(
    tool_context: ToolContext,
) -> None:
    registry = build_registry([_prepare_tool()])
    request = ToolCallRequest(id="c1", name="prepare_echo", arguments={"value": "x"})
    first = await registry.dispatch(request, tool_context)
    second = await registry.dispatch(
        ToolCallRequest(id="c2", name="prepare_echo", arguments={"value": "x"}), tool_context
    )
    assert first.idempotency_key == second.idempotency_key


# --- failure containment ----------------------------------------------------


async def test_handler_exception_becomes_a_tool_error(tool_context: ToolContext) -> None:
    async def _boom(ctx: ToolContext, args: EchoArgs) -> dict[str, Any]:
        raise RuntimeError("database is on fire")

    registry = build_registry(
        [
            ToolDescriptor(
                name="boom",
                description="fails",
                tier=ToolTier.READ,
                args_model=EchoArgs,
                handler=_boom,
            )
        ]
    )
    result = await registry.dispatch(
        ToolCallRequest(id="c1", name="boom", arguments={"value": "x"}), tool_context
    )
    assert result.status == "error"
    assert "database is on fire" in (result.error or "")


def test_duplicate_registration_is_rejected() -> None:
    registry = ToolRegistry()
    registry.register(_read_tool())
    with pytest.raises(ValueError, match="already registered"):
        registry.register(_read_tool())


def test_tool_specs_expose_json_schema() -> None:
    registry = build_registry(default_tools(StubServices()))
    spec = next(s for s in registry.specs() if s.name == "get_job")
    assert spec.parameters["properties"]["job_id"]["format"] == "uuid"
    assert JobIdArgs.model_json_schema() == spec.parameters
