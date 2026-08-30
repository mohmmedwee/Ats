"""Scoring against the database.

The scoring itself stays pure in ``scoring.py``. This module only builds the
views it needs, persists the result, and keeps the stored score consistent with
the inputs it was computed from.
"""

from __future__ import annotations

import uuid
from dataclasses import dataclass

from job_agent_ai.provider import AIProvider
from job_agent_domain.enums import FactKind, FactProvenance, MatchRouting, Seniority
from job_agent_domain.models import (
    CandidateFact,
    CandidateProfile,
    Job,
    JobMatch,
    MatchEvidence,
)
from job_agent_observability import get_logger
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from job_agent_matching.embeddings import semantic_similarity
from job_agent_matching.explain import GroundedExplanation, explain_match
from job_agent_matching.preferences import SearchPreferences
from job_agent_matching.scoring import MatchResult, score_job
from job_agent_matching.skills import canonical
from job_agent_matching.types import CandidateView, JobView

log = get_logger("matching")

#: Provenance a fact must have before it can justify a score. A generated draft
#: the user has not confirmed is not evidence of anything.
SCOREABLE_PROVENANCE = frozenset({FactProvenance.USER_CONFIRMED, FactProvenance.CV_DERIVED})


class ScoreOutcome(BaseModel):
    job_id: uuid.UUID
    match_id: uuid.UUID
    score: float
    routing: MatchRouting
    reused: bool = False
    explained: bool = False


@dataclass(slots=True)
class ScoreRunReport:
    scored: int = 0
    reused: int = 0
    explained: int = 0
    outcomes: list[ScoreOutcome] | None = None


def _seniority_from(facts: list[CandidateFact], headline: str | None) -> Seniority:
    """Read the candidate's level from their own words.

    Deliberately conservative: an unknown level scores on years of experience
    rather than on a guess.
    """
    from job_agent_connectors.normalize import detect_seniority

    text = " ".join(
        [headline or ""] + [f.value for f in facts if f.kind in (FactKind.ROLE, FactKind.HEADLINE)]
    )
    return detect_seniority(text)


def build_candidate_view(profile: CandidateProfile, facts: list[CandidateFact]) -> CandidateView:
    scoreable = [fact for fact in facts if fact.provenance in SCOREABLE_PROVENANCE]

    skills: dict[str, str] = {}
    roles: dict[str, str] = {}
    achievements: dict[str, str] = {}
    for fact in scoreable:
        if fact.kind is FactKind.SKILL:
            skills.setdefault(canonical(fact.value), str(fact.id))
        elif fact.kind in (FactKind.ROLE, FactKind.EMPLOYER):
            roles[str(fact.id)] = fact.value
        elif fact.kind in (FactKind.ACHIEVEMENT, FactKind.CERTIFICATION):
            achievements[str(fact.id)] = fact.value

    return CandidateView(
        profile_id=str(profile.id),
        profile_version=profile.version,
        headline=profile.headline,
        location=profile.location,
        country=(profile.location or "").split(",")[-1].strip() or None,
        years_experience=profile.years_experience,
        seniority=_seniority_from(scoreable, profile.headline),
        skills=skills,
        roles=roles,
        achievements=achievements,
    )


def build_job_view(job: Job) -> JobView:
    return JobView(
        id=str(job.id),
        company=job.company,
        title=job.title,
        normalized_title=job.normalized_title or "",
        seniority=job.seniority,
        description=job.description,
        location=job.location,
        city=job.city,
        country=job.country,
        remote_type=job.remote_type,
        employment_type=job.employment_type,
        compensation=job.compensation,
        required_skills=job.required_skills,
        preferred_skills=job.preferred_skills,
        responsibilities=job.responsibilities,
        visa_sponsorship=job.visa_sponsorship,
        content_hash=job.content_hash,
    )


async def persist(
    session: AsyncSession,
    *,
    job: Job,
    profile: CandidateProfile,
    result: MatchResult,
    semantic: float | None,
    embedding_model: str | None,
    explanation: GroundedExplanation | None,
) -> JobMatch:
    """Write a score, replacing any earlier one for the same job and profile.

    The unique constraint is on ``(job_id, profile_id, inputs_hash)``, so a
    re-score with identical inputs finds the existing row instead of creating a
    second opinion about the same thing.
    """
    existing = await session.execute(
        select(JobMatch).where(JobMatch.job_id == job.id, JobMatch.profile_id == profile.id)
    )
    match = existing.scalars().first()

    if match is None:
        # Every non-nullable column is set before the flush; inserting a
        # placeholder row first violates the inputs_hash constraint.
        match = JobMatch(
            job_id=job.id,
            profile_id=profile.id,
            score=result.score,
            routing=result.routing,
            inputs_hash=result.inputs_hash,
        )
        session.add(match)
        await session.flush()
    else:
        # Evidence belongs to one computation; a re-score replaces it rather
        # than accumulating contradictory rows.
        for row in (
            await session.execute(select(MatchEvidence).where(MatchEvidence.match_id == match.id))
        ).scalars():
            await session.delete(row)

    match.score = result.score
    match.routing = result.routing
    match.breakdown = result.breakdown()
    match.hard_blockers = {"blockers": [blocker.as_dict() for blocker in result.hard_blockers]}
    match.matched_requirements = {
        "items": [
            item.as_dict() for item in result.evidence if item.kind.value == "matched_requirement"
        ]
    }
    match.missing_requirements = {
        "items": [
            item.as_dict() for item in result.evidence if item.kind.value == "missing_requirement"
        ]
    }
    match.inputs_hash = result.inputs_hash
    match.semantic_similarity = semantic
    match.embedding_model = embedding_model
    if explanation is not None:
        match.explanation = explanation.summary or None
        match.explanation_data = explanation.model_dump(mode="json")

    for item in result.evidence:
        session.add(
            MatchEvidence(
                match_id=match.id,
                kind=item.kind.value,
                dimension=item.dimension,
                requirement=item.requirement,
                reference=item.reference,
                detail=item.detail,
                source=item.source,
            )
        )

    return match


async def score_one(
    session: AsyncSession,
    *,
    job: Job,
    profile: CandidateProfile,
    facts: list[CandidateFact],
    provider: AIProvider | None = None,
    embedding_model: str | None = None,
    explain: bool = False,
    force: bool = False,
) -> ScoreOutcome:
    """Score one job for one profile.

    When the inputs are unchanged the stored score is reused: recomputing would
    produce the same number, and re-running the model would spend tokens to
    rewrite the same paragraph.
    """
    candidate = build_candidate_view(profile, facts)
    job_view = build_job_view(job)
    preferences = SearchPreferences.from_profile(profile.preferences)

    semantic: float | None = None
    if provider is not None:
        semantic = await semantic_similarity(provider, job_view, candidate)

    result = score_job(
        job_view,
        candidate,
        preferences,
        semantic_similarity=semantic,
        embedding_model=embedding_model if semantic is not None else None,
    )

    existing = (
        (
            await session.execute(
                select(JobMatch).where(JobMatch.job_id == job.id, JobMatch.profile_id == profile.id)
            )
        )
        .scalars()
        .first()
    )
    if existing is not None and existing.inputs_hash == result.inputs_hash and not force:
        return ScoreOutcome(
            job_id=job.id,
            match_id=existing.id,
            score=existing.score,
            routing=existing.routing,
            reused=True,
        )

    explanation: GroundedExplanation | None = None
    if explain and provider is not None:
        explanation = await explain_match(
            provider, job=job_view, candidate=candidate, result=result
        )

    match = await persist(
        session,
        job=job,
        profile=profile,
        result=result,
        semantic=semantic,
        embedding_model=embedding_model if semantic is not None else None,
        explanation=explanation,
    )
    return ScoreOutcome(
        job_id=job.id,
        match_id=match.id,
        score=match.score,
        routing=match.routing,
        explained=explanation is not None and explanation.error is None,
    )


async def score_all(
    session: AsyncSession,
    *,
    profile: CandidateProfile,
    facts: list[CandidateFact],
    provider: AIProvider | None = None,
    embedding_model: str | None = None,
    explain: bool = False,
    limit: int | None = None,
) -> ScoreRunReport:
    """Score every job that is not a linked duplicate."""
    query = select(Job).where(Job.possible_duplicate_of.is_(None)).order_by(Job.fetched_at.desc())
    if limit is not None:
        query = query.limit(limit)

    report = ScoreRunReport(outcomes=[])
    for job in (await session.execute(query)).scalars():
        outcome = await score_one(
            session,
            job=job,
            profile=profile,
            facts=facts,
            provider=provider,
            embedding_model=embedding_model,
            explain=explain,
        )
        assert report.outcomes is not None
        report.outcomes.append(outcome)
        if outcome.reused:
            report.reused += 1
        else:
            report.scored += 1
        if outcome.explained:
            report.explained += 1

    log.info(
        "scoring_finished",
        scored=report.scored,
        reused=report.reused,
        explained=report.explained,
    )
    return report
