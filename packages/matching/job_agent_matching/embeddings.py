"""Embedding similarity between a candidate and a posting.

A supporting signal, never the score. It catches a role described in words the
CV does not reuse, but it cannot be shown to the user as a reason, so it is
capped at a minority of one dimension (see ``SEMANTIC_WEIGHT``).
"""

from __future__ import annotations

import math

from job_agent_ai.provider import AIProvider, ProviderError

from job_agent_matching.types import CandidateView, JobView

#: Enough text to characterise the role without paying for the whole posting.
MAX_CHARS = 4000


def cosine(left: list[float], right: list[float]) -> float:
    if not left or not right or len(left) != len(right):
        return 0.0
    dot = sum(a * b for a, b in zip(left, right, strict=True))
    left_norm = math.sqrt(sum(a * a for a in left))
    right_norm = math.sqrt(sum(b * b for b in right))
    if not left_norm or not right_norm:
        return 0.0
    # Clamped to [0, 1]: a negative cosine is not a negative fit, it is no fit.
    return max(0.0, min(1.0, dot / (left_norm * right_norm)))


def job_text(job: JobView) -> str:
    parts = [job.title, " ".join(job.required_skills), " ".join(job.responsibilities)]
    parts.append(job.description)
    return "\n".join(part for part in parts if part)[:MAX_CHARS]


def candidate_text(candidate: CandidateView) -> str:
    parts = [
        candidate.headline or "",
        " ".join(sorted(candidate.skills)),
        " ".join(candidate.roles.values()),
        " ".join(candidate.achievements.values()),
    ]
    return "\n".join(part for part in parts if part)[:MAX_CHARS]


async def semantic_similarity(
    provider: AIProvider, job: JobView, candidate: CandidateView
) -> float | None:
    """Cosine similarity, or None when embeddings are unavailable.

    Returning None rather than 0.0 matters: a missing signal must leave the
    deterministic score untouched, not push role fit down.
    """
    try:
        vectors = await provider.embed([job_text(job), candidate_text(candidate)])
    except (ProviderError, NotImplementedError):
        return None
    if len(vectors) != 2:
        return None
    return cosine(vectors[0], vectors[1])
