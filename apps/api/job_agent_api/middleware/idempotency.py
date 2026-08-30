"""Idempotency-Key enforcement.

Plan section 9: all mutation endpoints require an idempotency key. Enforcing it
in middleware means a new router cannot forget to.
"""

from __future__ import annotations

from collections.abc import Awaitable, Callable

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response

IDEMPOTENCY_HEADER = "Idempotency-Key"
MUTATING_METHODS = frozenset({"POST", "PATCH", "PUT", "DELETE"})

#: Paths that mutate nothing an employer can see and are safe to retry blindly.
EXEMPT_PREFIXES: tuple[str, ...] = ("/health", "/ready", "/docs", "/openapi.json")


class IdempotencyMiddleware(BaseHTTPMiddleware):
    async def dispatch(
        self, request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        path = request.url.path
        needs_key = (
            request.method in MUTATING_METHODS
            and not path.startswith(EXEMPT_PREFIXES)
            and not request.headers.get(IDEMPOTENCY_HEADER)
        )
        if needs_key:
            return JSONResponse(
                status_code=428,
                content={
                    "detail": f"{IDEMPOTENCY_HEADER} header is required for {request.method}",
                },
            )
        return await call_next(request)
