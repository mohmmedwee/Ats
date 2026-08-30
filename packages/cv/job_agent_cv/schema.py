"""The schema an LLM must produce when reading a CV.

Every field is optional and every list defaults to empty: a model that cannot
find something must be able to say so, because the alternative is that it
invents something to fill the slot.
"""

from __future__ import annotations

from pydantic import BaseModel, Field


class ExtractedRole(BaseModel):
    company: str
    title: str
    start_date: str | None = None
    end_date: str | None = None
    location: str | None = None
    achievements: list[str] = Field(default_factory=list)


class ExtractedEducation(BaseModel):
    institution: str
    qualification: str | None = None
    year: str | None = None


class ExtractedProfile(BaseModel):
    """Structured view of one CV."""

    headline: str | None = None
    summary: str | None = None
    location: str | None = None
    years_experience: float | None = Field(default=None, ge=0, le=70)
    skills: list[str] = Field(default_factory=list)
    roles: list[ExtractedRole] = Field(default_factory=list)
    education: list[ExtractedEducation] = Field(default_factory=list)
    certifications: list[str] = Field(default_factory=list)
    languages: list[str] = Field(default_factory=list)
    links: list[str] = Field(default_factory=list)
