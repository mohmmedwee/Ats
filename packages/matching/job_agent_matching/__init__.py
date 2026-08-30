"""Matching: hard filters, weighted scoring, embeddings, and explanation.

The number is deterministic and computed here. The model only writes prose
about a result it did not produce.
"""

from job_agent_matching.compensation import Compensation
from job_agent_matching.compensation import parse as parse_compensation
from job_agent_matching.embeddings import cosine, semantic_similarity
from job_agent_matching.evidence import Evidence, EvidenceKind
from job_agent_matching.explain import (
    GroundedExplanation,
    MatchExplanation,
    explain_match,
    ground,
)
from job_agent_matching.filters import HardBlocker, apply_hard_filters
from job_agent_matching.preferences import CompensationFloor, SearchPreferences
from job_agent_matching.scoring import (
    HIGH_PRIORITY_SCORE,
    NORMAL_REVIEW_SCORE,
    POSSIBLE_MATCH_SCORE,
    WEIGHTS,
    DimensionScore,
    MatchResult,
    inputs_hash,
    route,
    score_job,
)
from job_agent_matching.skills import canonical, canonical_set
from job_agent_matching.types import CandidateView, JobView

__all__ = [
    "HIGH_PRIORITY_SCORE",
    "NORMAL_REVIEW_SCORE",
    "POSSIBLE_MATCH_SCORE",
    "WEIGHTS",
    "CandidateView",
    "Compensation",
    "CompensationFloor",
    "DimensionScore",
    "Evidence",
    "EvidenceKind",
    "GroundedExplanation",
    "HardBlocker",
    "JobView",
    "MatchExplanation",
    "MatchResult",
    "SearchPreferences",
    "apply_hard_filters",
    "canonical",
    "canonical_set",
    "cosine",
    "explain_match",
    "ground",
    "inputs_hash",
    "parse_compensation",
    "route",
    "score_job",
    "semantic_similarity",
]
