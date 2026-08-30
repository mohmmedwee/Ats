"""Request and response models for sources, discovery, and jobs."""

from __future__ import annotations

import uuid
from datetime import datetime
from typing import Any

from job_agent_domain.enums import DuplicateReason, RemoteType, Seniority
from pydantic import BaseModel, ConfigDict, Field


class SourceRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    kind: str
    name: str
    config: dict[str, Any]
    enabled: bool
    auto_submit_allowed: bool
    rate_limit_per_minute: int
    cursor: str | None = None
    last_run_at: datetime | None = None
    last_success_at: datetime | None = None
    last_error: str | None = None
    consecutive_failures: int
    paused_until: datetime | None = None


class SourceCreate(BaseModel):
    kind: str
    name: str = Field(min_length=1, max_length=200)
    config: dict[str, Any] = Field(default_factory=dict)
    enabled: bool = True
    rate_limit_per_minute: int = Field(default=30, ge=1, le=600)


class SourceUpdate(BaseModel):
    """Auto-submit allow-listing is deliberately absent.

    Plan section 4 puts that behind the policies screen, per source, off by
    default. It is not something a source edit can flip in passing.
    """

    name: str | None = Field(default=None, min_length=1, max_length=200)
    config: dict[str, Any] | None = None
    enabled: bool | None = None
    rate_limit_per_minute: int | None = Field(default=None, ge=1, le=600)
    #: Setting this clears the failure count and the backoff window.
    reset_failures: bool = False
    #: Setting this makes the next run re-read the whole board.
    reset_cursor: bool = False


class JobRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    source_id: uuid.UUID
    external_id: str
    company: str
    title: str
    normalized_title: str | None = None
    seniority: Seniority
    location: str | None = None
    city: str | None = None
    country: str | None = None
    remote_type: RemoteType
    employment_type: str | None = None
    compensation: dict[str, Any] | None = None
    application_url: str
    canonical_url: str | None = None
    required_skills: list[str] = Field(default_factory=list)
    preferred_skills: list[str] = Field(default_factory=list)
    responsibilities: list[str] = Field(default_factory=list)
    visa_sponsorship: bool | None = None
    posted_at: datetime | None = None
    fetched_at: datetime
    injection_flagged: bool
    injection_signals: list[str] = Field(default_factory=list)
    possible_duplicate_of: uuid.UUID | None = None
    duplicate_reason: DuplicateReason | None = None
    duplicate_confidence: float | None = None


class JobDetail(JobRead):
    description: str
    #: Provenance: which fetch each normalised field came from.
    snapshots: list[SnapshotRead] = Field(default_factory=list)


class SnapshotRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    source_url: str
    fetched_at: datetime
    content_hash: str


class SourceResultRead(BaseModel):
    source_id: uuid.UUID
    source_name: str
    kind: str
    fetched: int
    created: int
    updated: int
    duplicates_linked: int
    injection_flagged: int
    skipped: bool
    error: str | None = None


class DiscoveryReportRead(BaseModel):
    started_at: datetime
    finished_at: datetime
    results: list[SourceResultRead]


class JobPage(BaseModel):
    items: list[JobRead]
    total: int
    limit: int
    offset: int
