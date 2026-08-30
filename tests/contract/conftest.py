"""Contract-test fixtures.

Every adapter is exercised against a recorded response replayed through an
``httpx.MockTransport``. No test in this directory opens a socket, so CI never
touches a real job board.
"""

from __future__ import annotations

import json
import pathlib
from collections.abc import Callable

import httpx
import pytest

FIXTURES = pathlib.Path(__file__).resolve().parents[2] / "fixtures" / "jobs"


def load(name: str) -> object:
    return json.loads((FIXTURES / name).read_text())


@pytest.fixture
def recorded() -> Callable[[str], object]:
    return load


@pytest.fixture
def mock_client() -> Callable[..., httpx.AsyncClient]:
    """Build a client that replays one payload, recording the requests it saw."""

    def build(
        payload: object,
        *,
        status_code: int = 200,
        requests: list[httpx.Request] | None = None,
    ) -> httpx.AsyncClient:
        def handler(request: httpx.Request) -> httpx.Response:
            if requests is not None:
                requests.append(request)
            return httpx.Response(status_code, json=payload)

        return httpx.AsyncClient(transport=httpx.MockTransport(handler))

    return build
