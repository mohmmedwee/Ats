"""Request and response models for scoring and the review queue."""

from __future__ import annotations

import uuid
from datetime import datetime
from typing import Any

from job_agent_domain.enums import MatchRouting, RemoteType, Seniority
from pydantic import BaseModel, ConfigDict, Field


class EvidenceRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    kind: str
    dimension: str
    requirement: str
    reference: str | None = None
    detail: str | None = None
    source: str


class MatchSummary(BaseModel):
    """One row of the review queue."""

    match_id: uuid.UUID
    job_id: uuid.UUID
    score: float
    routing: MatchRouting
    company: str
    title: str
    location: str | None = None
    remote_type: RemoteType
    seniority: Seniority
    application_url: str
    posted_at: datetime | None = None
    injection_flagged: bool = False
    #: The headline reasons, so the queue is scannable without opening each job.
    top_strengths: list[str] = Field(default_factory=list)
    top_gaps: list[str] = Field(default_factory=list)
    blocker_reasons: list[str] = Field(default_factory=list)
    shortlisted: bool = False


class MatchPage(BaseModel):
    items: list[MatchSummary]
    total: int
    limit: int
    offset: int
    counts_by_routing: dict[str, int] = Field(default_factory=dict)


class MatchDetail(BaseModel):
    match_id: uuid.UUID
    job_id: uuid.UUID
    score: float
    routing: MatchRouting
    breakdown: dict[str, Any]
    hard_blockers: list[dict[str, Any]] = Field(default_factory=list)
    evidence: list[EvidenceRead] = Field(default_factory=list)
    explanation: str | None = None
    explanation_data: dict[str, Any] = Field(default_factory=dict)
    semantic_similarity: float | None = None
    embedding_model: str | None = None
    #: Identifies the exact inputs this score came from.
    inputs_hash: str
    scored_at: datetime


class ScoreRequest(BaseModel):
    #: Ask the model for a written explanation as well as the number.
    explain: bool = False
    #: Recompute even when the inputs are unchanged.
    force: bool = False


class ScoreRunRequest(ScoreRequest):
    limit: int | None = Field(default=None, ge=1, le=500)


class ScoreRunReportRead(BaseModel):
    scored: int
    reused: int
    explained: int
