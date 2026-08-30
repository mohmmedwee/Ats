"""Job source connectors.

Adapters are thin: they discover, fetch, and map. Rate limiting, retries,
deduplication, and persistence are handled once, outside them.
"""

from job_agent_connectors.ashby import AshbySource
from job_agent_connectors.base import DiscoveryBatch, JobSource, RawJob, SourceConfigError
from job_agent_connectors.dedup import (
    MERGE_THRESHOLD,
    DuplicateMatch,
    ExistingJob,
    IncomingJob,
    find_duplicate,
)
from job_agent_connectors.greenhouse import GreenhouseSource
from job_agent_connectors.http import RateLimiter, SourceClient, SourceFetchError
from job_agent_connectors.lever import LeverSource
from job_agent_connectors.normalize import (
    NormalizedJob,
    canonicalize_url,
    content_hash,
    detect_remote_type,
    detect_seniority,
    detect_sponsorship,
    extract_requirements,
    fingerprint,
    fold,
    normalize_employment_type,
    normalize_title,
    split_location,
    strip_html,
)
from job_agent_connectors.registry import SUPPORTED_KINDS, UnknownSourceKindError, build_source

__all__ = [
    "MERGE_THRESHOLD",
    "SUPPORTED_KINDS",
    "AshbySource",
    "DiscoveryBatch",
    "DuplicateMatch",
    "ExistingJob",
    "GreenhouseSource",
    "IncomingJob",
    "JobSource",
    "LeverSource",
    "NormalizedJob",
    "RateLimiter",
    "RawJob",
    "SourceClient",
    "SourceConfigError",
    "SourceFetchError",
    "UnknownSourceKindError",
    "build_source",
    "canonicalize_url",
    "content_hash",
    "detect_remote_type",
    "detect_seniority",
    "detect_sponsorship",
    "extract_requirements",
    "find_duplicate",
    "fingerprint",
    "fold",
    "normalize_employment_type",
    "normalize_title",
    "split_location",
    "strip_html",
]
