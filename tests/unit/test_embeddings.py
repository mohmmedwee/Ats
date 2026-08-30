"""Embedding similarity as a bounded, optional signal."""

from __future__ import annotations

from job_agent_ai import FakeProvider
from job_agent_ai.provider import ProviderError
from job_agent_matching.embeddings import candidate_text, cosine, job_text, semantic_similarity
from job_agent_matching.types import CandidateView, JobView

JOB = JobView(id="j1", company="Acme", title="Backend Engineer", description="Python services")
CANDIDATE = CandidateView(profile_id="p1", headline="Backend Engineer", skills={"python": "f1"})


def test_cosine_of_identical_vectors_is_one() -> None:
    assert cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]) == 1.0


def test_cosine_is_clamped_to_zero_at_the_bottom() -> None:
    """A negative cosine is not a negative fit; it is no fit."""
    assert cosine([1.0, 0.0], [-1.0, 0.0]) == 0.0


def test_mismatched_or_empty_vectors_are_zero() -> None:
    assert cosine([], [1.0]) == 0.0
    assert cosine([1.0, 2.0], [1.0]) == 0.0
    assert cosine([0.0, 0.0], [0.0, 0.0]) == 0.0


async def test_similarity_uses_both_texts() -> None:
    provider = FakeProvider()
    value = await semantic_similarity(provider, JOB, CANDIDATE)
    assert value is not None
    assert 0.0 <= value <= 1.0


async def test_an_unavailable_provider_yields_none_not_zero() -> None:
    """A missing signal must leave the deterministic score untouched."""

    class Broken(FakeProvider):
        async def embed(self, texts):  # type: ignore[no-untyped-def]
            raise ProviderError("no embedding endpoint")

    assert await semantic_similarity(Broken(), JOB, CANDIDATE) is None


def test_texts_are_truncated() -> None:
    long_job = JOB.model_copy(update={"description": "x" * 20_000})
    assert len(job_text(long_job)) <= 4000
    assert candidate_text(CANDIDATE)
