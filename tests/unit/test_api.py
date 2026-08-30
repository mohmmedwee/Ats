"""API surface behaviour that does not need a database."""

from __future__ import annotations

import httpx
from job_agent_domain.enums import ToolTier


async def test_health_is_liveness_only(api_client: httpx.AsyncClient) -> None:
    response = await api_client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


async def test_request_id_is_echoed(api_client: httpx.AsyncClient) -> None:
    response = await api_client.get("/health", headers={"X-Request-ID": "abc-123"})
    assert response.headers["X-Request-ID"] == "abc-123"


async def test_request_id_is_generated_when_absent(api_client: httpx.AsyncClient) -> None:
    response = await api_client.get("/health")
    assert response.headers.get("X-Request-ID")


async def test_mutations_require_an_idempotency_key(api_client: httpx.AsyncClient) -> None:
    response = await api_client.post("/api/v1/discovery/run", json={})
    assert response.status_code == 428
    assert "Idempotency-Key" in response.json()["detail"]


async def test_health_is_exempt_from_the_idempotency_requirement(
    api_client: httpx.AsyncClient,
) -> None:
    response = await api_client.get("/health")
    assert response.status_code == 200


async def test_policy_endpoint_reports_auto_submit_disabled(api_client: httpx.AsyncClient) -> None:
    response = await api_client.get("/api/v1/policy")
    assert response.status_code == 200
    body = response.json()
    assert body["auto_submit_enabled"] is False
    assert body["autonomy_level"] == 2


async def test_chat_tools_endpoint_advertises_no_external_tool(
    api_client: httpx.AsyncClient,
) -> None:
    response = await api_client.get("/api/v1/chat/tools")
    assert response.status_code == 200
    body = response.json()
    assert all(tool["tier"] != ToolTier.EXTERNAL.value for tool in body["tools"])
    assert {action["name"] for action in body["external_actions"]} == {
        "add_source",
        "set_autonomy_level",
        "start_form",
        "submit_application",
    }
    assert all(action["callable_from_chat"] is False for action in body["external_actions"])
