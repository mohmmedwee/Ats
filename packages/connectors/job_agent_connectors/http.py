"""Shared HTTP behaviour for connectors: rate limiting and bounded retries.

Both live here rather than in each adapter so a new connector cannot forget to
be polite to someone else's board.
"""

from __future__ import annotations

import asyncio
import random
import time
from types import TracebackType
from typing import Any

import httpx
from job_agent_observability import get_logger

log = get_logger("connectors.http")

#: Retried: the source is busy or briefly broken. Everything else is a real
#: answer and is surfaced, including 404 for a board name that does not exist.
RETRY_STATUS = frozenset({408, 425, 429, 500, 502, 503, 504})
MAX_ATTEMPTS = 3
BASE_BACKOFF_SECONDS = 1.0
MAX_BACKOFF_SECONDS = 30.0


class SourceFetchError(RuntimeError):
    """The source could not be read. Carries the status when there was one."""

    def __init__(self, message: str, *, status_code: int | None = None) -> None:
        super().__init__(message)
        self.status_code = status_code


class RateLimiter:
    """Minimum spacing between requests to one source.

    A simple interval limiter rather than a token bucket: job boards are polled
    a few times a day, so smoothing matters more than burst capacity.
    """

    def __init__(self, per_minute: int) -> None:
        self._interval = 60.0 / max(1, per_minute)
        self._last: float = 0.0
        self._lock = asyncio.Lock()

    async def acquire(self) -> None:
        async with self._lock:
            now = time.monotonic()
            wait = self._interval - (now - self._last)
            if wait > 0:
                await asyncio.sleep(wait)
            self._last = time.monotonic()


def backoff_delay(attempt: int, retry_after: float | None = None) -> float:
    """Exponential with jitter. A Retry-After header wins if the source sent one."""
    if retry_after is not None:
        return min(retry_after, MAX_BACKOFF_SECONDS)
    # 2.0 rather than 2: int ** int widens to Any under strict typing.
    delay = min(BASE_BACKOFF_SECONDS * 2.0 ** (attempt - 1), MAX_BACKOFF_SECONDS)
    return delay * (0.5 + random.random() / 2)  # noqa: S311 - jitter, not cryptography


def _retry_after(response: httpx.Response) -> float | None:
    raw = response.headers.get("Retry-After")
    if raw is None:
        return None
    try:
        return float(raw)
    except ValueError:
        # Retry-After may also be an HTTP date; our own backoff covers that.
        return None


class SourceClient:
    """An HTTP client scoped to one source, with its own rate limit."""

    def __init__(
        self,
        *,
        rate_limit_per_minute: int = 30,
        timeout: float = 20.0,
        client: httpx.AsyncClient | None = None,
        user_agent: str = "job-agent/0.1 (+https://github.com/mohmmedwee/Ats)",
        sleep: Any = asyncio.sleep,
    ) -> None:
        self._limiter = RateLimiter(rate_limit_per_minute)
        self._client = client or httpx.AsyncClient(
            timeout=timeout, headers={"User-Agent": user_agent, "Accept": "application/json"}
        )
        self._owns_client = client is None
        self._sleep = sleep

    async def get_json(self, url: str, *, params: dict[str, Any] | None = None) -> Any:
        last_error: Exception | None = None

        for attempt in range(1, MAX_ATTEMPTS + 1):
            await self._limiter.acquire()
            try:
                response = await self._client.get(url, params=params)
            except httpx.TimeoutException as exc:
                last_error = exc
                log.warning("source_timeout", url=url, attempt=attempt)
            except httpx.HTTPError as exc:
                # Transport failures are not retried: a DNS or TLS error will
                # not resolve itself within a run.
                raise SourceFetchError(f"request to {url} failed: {exc}") from exc
            else:
                if response.status_code in RETRY_STATUS:
                    last_error = SourceFetchError(
                        f"{url} returned {response.status_code}", status_code=response.status_code
                    )
                    log.warning(
                        "source_retryable_status",
                        url=url,
                        status=response.status_code,
                        attempt=attempt,
                    )
                    if attempt < MAX_ATTEMPTS:
                        await self._sleep(backoff_delay(attempt, _retry_after(response)))
                    continue
                if response.status_code >= 400:
                    raise SourceFetchError(
                        f"{url} returned {response.status_code}", status_code=response.status_code
                    )
                try:
                    return response.json()
                except ValueError as exc:
                    raise SourceFetchError(f"{url} returned invalid JSON") from exc

            if attempt < MAX_ATTEMPTS:
                await self._sleep(backoff_delay(attempt))

        raise SourceFetchError(f"giving up on {url} after {MAX_ATTEMPTS} attempts") from last_error

    async def aclose(self) -> None:
        if self._owns_client:
            await self._client.aclose()

    async def __aenter__(self) -> SourceClient:
        return self

    async def __aexit__(
        self,
        exc_type: type[BaseException] | None,
        exc: BaseException | None,
        tb: TracebackType | None,
    ) -> None:
        await self.aclose()
