"""The views matching reads.

Matching takes plain data, not ORM rows: the scorer must be callable from a
test, a fixture, or a request with equal ease, and it must not be able to
lazy-load something mid-score.
"""

from __future__ import annotations

from typing import Any

from job_agent_domain.enums import RemoteType, Seniority
from pydantic import BaseModel, Field


class JobView(BaseModel):
    """One normalised posting, as the scorer sees it."""

    id: str
    company: str
    title: str
    normalized_title: str = ""
    seniority: Seniority = Seniority.UNKNOWN
    description: str = ""
    location: str | None = None
    city: str | None = None
    country: str | None = None
    remote_type: RemoteType = RemoteType.UNKNOWN
    employment_type: str | None = None
    compensation: dict[str, Any] | None = None
    required_skills: list[str] = Field(default_factory=list)
    preferred_skills: list[str] = Field(default_factory=list)
    responsibilities: list[str] = Field(default_factory=list)
    visa_sponsorship: bool | None = None
    #: Changes whenever the posting text changes; part of the reproducibility hash.
    content_hash: str = ""


class CandidateView(BaseModel):
    """The candidate, reduced to what a score can be justified by.

    Facts carry their ids so every matched requirement can point at the fact
    that supports it.
    """

    profile_id: str
    profile_version: int = 1
    headline: str | None = None
    location: str | None = None
    country: str | None = None
    years_experience: float | None = None
    seniority: Seniority = Seniority.UNKNOWN
    #: canonical skill -> fact id that supports it.
    skills: dict[str, str] = Field(default_factory=dict)
    #: fact id -> value, for roles, achievements, and other free-text facts.
    roles: dict[str, str] = Field(default_factory=dict)
    achievements: dict[str, str] = Field(default_factory=dict)
    #: Only facts the user confirmed or that came from the CV are included; a
    #: generated draft never justifies a score.
    confirmed_only: bool = True
