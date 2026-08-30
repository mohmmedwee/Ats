"""Request and response models for the profile and resume endpoints."""

from __future__ import annotations

import uuid
from datetime import datetime

from job_agent_domain.enums import FactKind, FactProvenance, ResumeParseStatus
from pydantic import BaseModel, ConfigDict, Field


class FactRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    kind: FactKind
    value: str
    provenance: FactProvenance
    evidence_ref: str | None = None
    confirmed_at: datetime | None = None
    sort_order: int = 0


class FactCreate(BaseModel):
    """A fact the user is adding by hand.

    There is no provenance field: anything a person types here is
    ``user_confirmed`` by definition, and anything the system produces cannot
    reach this endpoint.
    """

    kind: FactKind
    value: str = Field(min_length=1, max_length=2000)
    evidence_ref: str | None = Field(default=None, max_length=500)


class FactUpdate(BaseModel):
    value: str | None = Field(default=None, min_length=1, max_length=2000)
    sort_order: int | None = None


class ProfileRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    headline: str | None = None
    location: str | None = None
    years_experience: float | None = None
    preferences: dict[str, object] = Field(default_factory=dict)
    locked_fields: list[str] = Field(default_factory=list)
    version: int
    facts: list[FactRead] = Field(default_factory=list)


class ProfileUpdate(BaseModel):
    """Editing a field locks it, so a later re-parse leaves it alone."""

    headline: str | None = Field(default=None, max_length=300)
    location: str | None = Field(default=None, max_length=200)
    years_experience: float | None = Field(default=None, ge=0, le=70)
    preferences: dict[str, object] | None = None


class ResumeRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    filename: str
    content_type: str
    byte_size: int
    sha256: str
    parse_status: ResumeParseStatus
    parsed_at: datetime | None = None
    parse_error: str | None = None
    is_primary: bool
    created_at: datetime


class RejectedClaim(BaseModel):
    kind: FactKind
    value: str


class ParseReport(BaseModel):
    """What a parse did, including what it refused to keep."""

    resume_id: uuid.UUID
    status: ResumeParseStatus
    facts_added: int
    facts_withdrawn: int
    facts_kept: int
    #: Values the model produced that the CV does not support. Shown to the user
    #: rather than dropped silently.
    rejected: list[RejectedClaim] = Field(default_factory=list)
    error: str | None = None


class AnswerRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    question: str
    question_key: str
    answer: str
    provenance: FactProvenance
    confirmed_at: datetime | None = None


class AnswerUpsert(BaseModel):
    question: str = Field(min_length=3, max_length=1000)
    answer: str = Field(min_length=1, max_length=5000)
    #: False stores the answer as a draft for later review.
    confirmed: bool = True
