"""Shared fixtures.

Tests never talk to a model provider or an employer. The AI provider is the
deterministic fake, and no test in this suite performs an external action.
"""

from __future__ import annotations

import os
import uuid
from collections.abc import AsyncIterator, Iterator

import pytest

os.environ.setdefault("ENV", "test")
os.environ.setdefault("AI_PROVIDER", "fake")
os.environ.setdefault("LOG_LEVEL", "WARNING")


@pytest.fixture(autouse=True)
def _reset_settings_cache() -> Iterator[None]:
    from job_agent_domain.settings import get_settings

    get_settings.cache_clear()
    yield
    get_settings.cache_clear()


@pytest.fixture
def settings():  # type: ignore[no-untyped-def]
    from job_agent_domain.settings import get_settings

    return get_settings()


@pytest.fixture
def fake_provider():  # type: ignore[no-untyped-def]
    from job_agent_ai import FakeProvider

    return FakeProvider()


@pytest.fixture
def user_id() -> uuid.UUID:
    return uuid.UUID("11111111-1111-1111-1111-111111111111")


@pytest.fixture
def tool_context(user_id: uuid.UUID):  # type: ignore[no-untyped-def]
    from job_agent_chat.tools import ToolContext

    return ToolContext(
        user_id=user_id,
        thread_id=uuid.UUID("22222222-2222-2222-2222-222222222222"),
        message_id=uuid.UUID("33333333-3333-3333-3333-333333333333"),
    )


@pytest.fixture
async def api_client() -> AsyncIterator[object]:
    import httpx
    from job_agent_api.main import create_app

    app = create_app()
    transport = httpx.ASGITransport(app=app)
    async with httpx.AsyncClient(transport=transport, base_url="http://test") as client:
        yield client


def build_pdf(text: str) -> bytes:
    """A minimal single-page PDF with a real text layer, built without a renderer.

    Used to exercise the PDF path and, with a very short body, the
    "this is a scan, it needs OCR" path.
    """
    stream = f"BT /F1 12 Tf 72 720 Td ({text}) Tj ET".encode("latin-1")
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
        b"/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
        b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"\nendstream",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]

    out = bytearray(b"%PDF-1.4\n")
    offsets: list[int] = []
    for index, body in enumerate(objects, start=1):
        offsets.append(len(out))
        out += f"{index} 0 obj\n".encode() + body + b"\nendobj\n"

    xref_at = len(out)
    out += f"xref\n0 {len(objects) + 1}\n".encode()
    out += b"0000000000 65535 f \n"
    for offset in offsets:
        out += f"{offset:010d} 00000 n \n".encode()
    out += (
        f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref_at}\n%%EOF\n"
    ).encode()
    return bytes(out)


@pytest.fixture
def make_pdf():  # type: ignore[no-untyped-def]
    return build_pdf
