"""The candidate's search preferences.

These are the answers to the onboarding questions in ``job-agent-plan.md``
section 2. They are stored as JSON on the profile and validated here, so a
malformed preference cannot silently disable a filter.
"""

from __future__ import annotations

from typing import Any

from job_agent_domain.enums import RemoteType, Seniority
from pydantic import BaseModel, Field, field_validator


class CompensationFloor(BaseModel):
    """A minimum, in one currency, at one cadence.

    No currency conversion is attempted: converting at an arbitrary rate would
    silently reject or accept roles on a number the user never chose.
    """

    amount: float = Field(ge=0)
    currency: str = Field(min_length=3, max_length=3)
    period: str = Field(default="year", pattern="^(year|month|day|hour)$")


class SearchPreferences(BaseModel):
    """Everything the hard filters and the scorer read.

    Every list defaults to empty and every option to permissive: an unanswered
    question must never reject a job on its own.
    """

    #: Country names the candidate will work in. Empty means anywhere.
    target_countries: list[str] = Field(default_factory=list)
    #: Acceptable location arrangements. Empty means any.
    remote_types: list[RemoteType] = Field(default_factory=list)
    #: Cities the candidate can commute to, for onsite and hybrid roles.
    commutable_cities: list[str] = Field(default_factory=list)

    minimum_compensation: CompensationFloor | None = None

    #: True when the candidate needs an employer to sponsor a visa. A posting
    #: that explicitly refuses to sponsor is then a hard blocker.
    requires_sponsorship: bool = False
    willing_to_relocate: bool = False

    #: Titles the candidate is aiming at, and ones to never show.
    desired_titles: list[str] = Field(default_factory=list)
    excluded_titles: list[str] = Field(default_factory=list)
    excluded_companies: list[str] = Field(default_factory=list)
    excluded_keywords: list[str] = Field(default_factory=list)

    minimum_seniority: Seniority | None = None
    maximum_seniority: Seniority | None = None

    notice_period_days: int | None = Field(default=None, ge=0, le=365)
    max_applications_per_day: int | None = Field(default=None, ge=0, le=100)

    @field_validator("target_countries", "commutable_cities", "excluded_companies")
    @classmethod
    def _strip(cls, value: list[str]) -> list[str]:
        return [item.strip() for item in value if item.strip()]

    @classmethod
    def from_profile(cls, preferences: dict[str, Any] | None) -> SearchPreferences:
        """Parse stored preferences, falling back to permissive defaults.

        Invalid stored preferences must not make every job unscoreable, so a
        parse failure yields the default rather than raising.
        """
        if not preferences:
            return cls()
        try:
            return cls.model_validate(preferences)
        except ValueError:
            return cls()
