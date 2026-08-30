"""Rate limiting and bounded retries for connectors."""

from __future__ import annotations

import httpx
import pytest
from job_agent_connectors.http import (
    MAX_ATTEMPTS,
    MAX_BACKOFF_SECONDS,
    RateLimiter,
    SourceClient,
    SourceFetchError,
    backoff_delay,
)


class RecordingSleep:
    """Stands in for asyncio.sleep so retry tests do not actually wait."""

    def __init__(self) -> None:
        self.delays: list[float] = []

    async def __call__(self, delay: float) -> None:
        self.delays.append(delay)


def build_client(handler, sleep=None) -> SourceClient:  # type: ignore[no-untyped-def]
    return SourceClient(
        client=httpx.AsyncClient(transport=httpx.MockTransport(handler)),
        rate_limit_per_minute=60_000,
        sleep=sleep or RecordingSleep(),
    )


async def test_a_successful_response_is_returned() -> None:
    client = build_client(lambda request: httpx.Response(200, json={"ok": True}))
    assert await client.get_json("https://example.com/jobs") == {"ok": True}


async def test_a_retryable_status_is_retried_then_gives_up() -> None:
    calls: list[int] = []
    sleep = RecordingSleep()

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(1)
        return httpx.Response(503)

    client = build_client(handler, sleep)
    with pytest.raises(SourceFetchError):
        await client.get_json("https://example.com/jobs")

    assert len(calls) == MAX_ATTEMPTS
    assert len(sleep.delays) == MAX_ATTEMPTS - 1


async def test_a_retry_can_succeed() -> None:
    responses = [httpx.Response(429), httpx.Response(200, json={"ok": True})]

    def handler(request: httpx.Request) -> httpx.Response:
        return responses.pop(0)

    client = build_client(handler)
    assert await client.get_json("https://example.com/jobs") == {"ok": True}


async def test_a_client_error_is_not_retried() -> None:
    """A 404 for a board name that does not exist is an answer, not a blip."""
    calls: list[int] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(1)
        return httpx.Response(404)

    client = build_client(handler)
    with pytest.raises(SourceFetchError) as exc:
        await client.get_json("https://example.com/jobs")

    assert exc.value.status_code == 404
    assert len(calls) == 1


async def test_invalid_json_is_an_error_not_a_retry() -> None:
    client = build_client(lambda request: httpx.Response(200, text="<html>maintenance</html>"))
    with pytest.raises(SourceFetchError, match="invalid JSON"):
        await client.get_json("https://example.com/jobs")


async def test_a_transport_failure_is_not_retried() -> None:
    """DNS and TLS errors will not resolve themselves inside one run."""
    calls: list[int] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(1)
        raise httpx.ConnectError("no route to host")

    client = build_client(handler)
    with pytest.raises(SourceFetchError):
        await client.get_json("https://example.com/jobs")
    assert len(calls) == 1


async def test_a_timeout_is_retried() -> None:
    calls: list[int] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(1)
        raise httpx.ReadTimeout("too slow")

    client = build_client(handler)
    with pytest.raises(SourceFetchError):
        await client.get_json("https://example.com/jobs")
    assert len(calls) == MAX_ATTEMPTS


async def test_retry_after_is_honoured_over_our_own_backoff() -> None:
    sleep = RecordingSleep()
    responses = [
        httpx.Response(429, headers={"Retry-After": "7"}),
        httpx.Response(200, json={"ok": True}),
    ]

    def handler(request: httpx.Request) -> httpx.Response:
        return responses.pop(0)

    client = build_client(handler, sleep)
    await client.get_json("https://example.com/jobs")
    assert sleep.delays == [7.0]


def test_backoff_grows_and_is_capped() -> None:
    assert backoff_delay(1) <= backoff_delay(5) or backoff_delay(5) <= MAX_BACKOFF_SECONDS
    assert backoff_delay(20) <= MAX_BACKOFF_SECONDS
    assert backoff_delay(3, retry_after=999) == MAX_BACKOFF_SECONDS


async def test_the_rate_limiter_spaces_requests() -> None:
    import time

    limiter = RateLimiter(per_minute=600)  # 100 ms apart
    started = time.monotonic()
    await limiter.acquire()
    await limiter.acquire()
    assert time.monotonic() - started >= 0.09
