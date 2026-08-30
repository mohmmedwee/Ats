"""The connector contract from ``job-agent-plan.md`` section 7.2.

Adapters are added in Phase 2. Defining the protocol in Phase 0 keeps the
worker's scheduling code honest before any adapter exists.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any, Protocol

from pydantic import BaseModel, Field


class RawJob(BaseModel):
    """Exactly what the source returned, plus provenance (plan section 7.2)."""

    external_id: str
    source_url: str
    fetched_at: datetime
    content_hash: str
    payload: dict[str, Any] = Field(default_factory=dict)


class DiscoveryBatch(BaseModel):
    jobs: list[RawJob] = Field(default_factory=list)
    #: Opaque checkpoint persisted per source so discovery resumes, not restarts.
    next_cursor: str | None = None
    has_more: bool = False


class JobSource(Protocol):
    kind: str

    async def discover(self, cursor: str | None) -> DiscoveryBatch: ...

    async def fetch_details(self, external_id: str) -> RawJob: ...
