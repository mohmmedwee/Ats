"""The tool set chat is allowed to have.

Handlers delegate to :class:`ChatServices`, which the API implements against the
database in Phase 8. Keeping the boundary here means the registry, the tiers,
and the argument schemas are testable now with a stub implementation.

Note what is absent: there is no ``submit_application``, ``start_form``,
``add_source``, or ``set_autonomy_level`` descriptor anywhere in this module.
Those are external actions and the registry refuses to hold them.
"""

from __future__ import annotations

import uuid
from typing import Any, Protocol

from job_agent_domain.enums import ToolTier
from pydantic import BaseModel, Field

from job_agent_chat.tools import ToolContext, ToolDescriptor


class SearchJobsArgs(BaseModel):
    query: str | None = Field(
        default=None, description="Free-text query over title and description"
    )
    min_score: float | None = Field(default=None, ge=0, le=100)
    company: str | None = None
    remote_only: bool = False
    limit: int = Field(default=10, ge=1, le=50)


class JobIdArgs(BaseModel):
    job_id: uuid.UUID


class ApplicationIdArgs(BaseModel):
    application_id: uuid.UUID


class DraftAnswerArgs(BaseModel):
    question: str = Field(min_length=3, max_length=2000)
    job_id: uuid.UUID | None = None


class UpdatePreferencesArgs(BaseModel):
    """Only the preference keys the onboarding flow owns (plan section 2).

    Autonomy level is deliberately not among them.
    """

    target_countries: list[str] | None = None
    remote_preference: str | None = None
    minimum_compensation: float | None = Field(default=None, ge=0)
    compensation_currency: str | None = Field(default=None, max_length=3)
    excluded_companies: list[str] | None = None
    excluded_titles: list[str] | None = None
    notice_period_days: int | None = Field(default=None, ge=0, le=365)


class ChatServices(Protocol):
    """What chat is allowed to reach. Nothing here writes to an employer."""

    async def search_jobs(self, user_id: uuid.UUID, args: SearchJobsArgs) -> dict[str, Any]: ...
    async def get_job(self, user_id: uuid.UUID, job_id: uuid.UUID) -> dict[str, Any]: ...
    async def get_match_explanation(
        self, user_id: uuid.UUID, job_id: uuid.UUID
    ) -> dict[str, Any]: ...
    async def get_pipeline_summary(self, user_id: uuid.UUID) -> dict[str, Any]: ...
    async def get_application_status(
        self, user_id: uuid.UUID, application_id: uuid.UUID
    ) -> dict[str, Any]: ...
    async def shortlist_job(self, user_id: uuid.UUID, job_id: uuid.UUID) -> dict[str, Any]: ...
    async def reject_job(self, user_id: uuid.UUID, job_id: uuid.UUID) -> dict[str, Any]: ...
    async def generate_application_pack(
        self, user_id: uuid.UUID, job_id: uuid.UUID
    ) -> dict[str, Any]: ...
    async def draft_answer(self, user_id: uuid.UUID, args: DraftAnswerArgs) -> dict[str, Any]: ...
    async def update_preferences(
        self, user_id: uuid.UUID, args: UpdatePreferencesArgs
    ) -> dict[str, Any]: ...


def default_tools(services: ChatServices) -> list[ToolDescriptor[Any]]:
    """Every tool chat gets, with its tier fixed here in code."""

    async def _search_jobs(ctx: ToolContext, args: SearchJobsArgs) -> dict[str, Any]:
        return await services.search_jobs(ctx.user_id, args)

    async def _get_job(ctx: ToolContext, args: JobIdArgs) -> dict[str, Any]:
        return await services.get_job(ctx.user_id, args.job_id)

    async def _get_match_explanation(ctx: ToolContext, args: JobIdArgs) -> dict[str, Any]:
        return await services.get_match_explanation(ctx.user_id, args.job_id)

    async def _get_pipeline_summary(ctx: ToolContext, _args: BaseModel) -> dict[str, Any]:
        return await services.get_pipeline_summary(ctx.user_id)

    async def _get_application_status(ctx: ToolContext, args: ApplicationIdArgs) -> dict[str, Any]:
        return await services.get_application_status(ctx.user_id, args.application_id)

    async def _shortlist_job(ctx: ToolContext, args: JobIdArgs) -> dict[str, Any]:
        return await services.shortlist_job(ctx.user_id, args.job_id)

    async def _reject_job(ctx: ToolContext, args: JobIdArgs) -> dict[str, Any]:
        return await services.reject_job(ctx.user_id, args.job_id)

    async def _generate_pack(ctx: ToolContext, args: JobIdArgs) -> dict[str, Any]:
        return await services.generate_application_pack(ctx.user_id, args.job_id)

    async def _draft_answer(ctx: ToolContext, args: DraftAnswerArgs) -> dict[str, Any]:
        return await services.draft_answer(ctx.user_id, args)

    async def _update_preferences(ctx: ToolContext, args: UpdatePreferencesArgs) -> dict[str, Any]:
        return await services.update_preferences(ctx.user_id, args)

    class NoArgs(BaseModel):
        pass

    return [
        ToolDescriptor(
            name="search_jobs",
            description="Search discovered jobs by text, score, company, or remote status.",
            tier=ToolTier.READ,
            args_model=SearchJobsArgs,
            handler=_search_jobs,
        ),
        ToolDescriptor(
            name="get_job",
            description="Get one job posting with its normalised fields.",
            tier=ToolTier.READ,
            args_model=JobIdArgs,
            handler=_get_job,
        ),
        ToolDescriptor(
            name="get_match_explanation",
            description=(
                "Get the stored score breakdown, matched and missing requirements, and hard "
                "blockers for a job."
            ),
            tier=ToolTier.READ,
            args_model=JobIdArgs,
            handler=_get_match_explanation,
        ),
        ToolDescriptor(
            name="get_pipeline_summary",
            description="Count applications by status across the pipeline.",
            tier=ToolTier.READ,
            args_model=NoArgs,
            handler=_get_pipeline_summary,
        ),
        ToolDescriptor(
            name="get_application_status",
            description="Get one application's status, blockers, and history.",
            tier=ToolTier.READ,
            args_model=ApplicationIdArgs,
            handler=_get_application_status,
        ),
        ToolDescriptor(
            name="shortlist_job",
            description="Move a job into the shortlist. Requires confirmation.",
            tier=ToolTier.PREPARE,
            args_model=JobIdArgs,
            handler=_shortlist_job,
        ),
        ToolDescriptor(
            name="reject_job",
            description="Reject a job so it stops appearing in the queue. Requires confirmation.",
            tier=ToolTier.PREPARE,
            args_model=JobIdArgs,
            handler=_reject_job,
        ),
        ToolDescriptor(
            name="generate_application_pack",
            description=(
                "Generate a tailored CV, cover letter, and suggested answers for a job. "
                "Requires confirmation. Does not apply."
            ),
            tier=ToolTier.PREPARE,
            args_model=JobIdArgs,
            handler=_generate_pack,
        ),
        ToolDescriptor(
            name="draft_answer",
            description=(
                "Draft an answer to an application question from verified facts, for the user "
                "to review. Requires confirmation."
            ),
            tier=ToolTier.PREPARE,
            args_model=DraftAnswerArgs,
            handler=_draft_answer,
        ),
        ToolDescriptor(
            name="update_preferences",
            description="Update search preferences. Requires confirmation.",
            tier=ToolTier.PREPARE,
            args_model=UpdatePreferencesArgs,
            handler=_update_preferences,
        ),
    ]
