"""The connector contract from ``job-agent-plan.md`` section 7.2.

Every adapter does three things: discover postings, fetch one posting's detail,
and map a raw payload into the shared normalised shape. Everything else — rate
limiting, retries, deduplication, persistence — is done once, outside them.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any, Protocol, runtime_checkable

from pydantic import BaseModel, Field

from job_agent_connectors.normalize import NormalizedJob


class SourceConfigError(ValueError):
    """The stored configuration for a source is missing something required."""


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


@runtime_checkable
class JobSource(Protocol):
    kind: str
    company: str

    async def discover(self, cursor: str | None) -> DiscoveryBatch: ...

    async def fetch_details(self, external_id: str) -> RawJob: ...

    def normalize(self, raw: RawJob) -> NormalizedJob: ...
