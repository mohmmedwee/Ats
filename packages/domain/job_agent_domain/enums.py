"""Enumerations shared across the system.

These mirror the state machines and policy tiers described in
``job-agent-plan.md`` sections 4, 7.6, and 7.8. They live in the domain package
so that the API, the worker, and the chat agent cannot drift apart on them.
"""

from __future__ import annotations

from enum import IntEnum, StrEnum


class AutonomyLevel(IntEnum):
    """Plan section 4. The chat agent inherits this and can never exceed it."""

    SCOUT = 0
    PREPARE = 1
    ASSISTED_APPLY = 2
    GUARDED_AUTO_SUBMIT = 3


class ApplicationStatus(StrEnum):
    """Plan section 7.6 workflow states."""

    DISCOVERED = "discovered"
    SCORED = "scored"
    SHORTLISTED = "shortlisted"
    PACK_READY = "pack_ready"
    APPROVED = "approved"
    FORM_STARTED = "form_started"
    NEEDS_INPUT = "needs_input"
    NEEDS_USER_ACTION = "needs_user_action"
    READY_TO_SUBMIT = "ready_to_submit"
    SUBMITTED = "submitted"
    CONFIRMED = "confirmed"
    FAILED = "failed"
    WITHDRAWN = "withdrawn"


#: Allowed transitions. Anything absent here is rejected by the orchestrator.
APPLICATION_TRANSITIONS: dict[ApplicationStatus, frozenset[ApplicationStatus]] = {
    ApplicationStatus.DISCOVERED: frozenset({ApplicationStatus.SCORED}),
    ApplicationStatus.SCORED: frozenset(
        {ApplicationStatus.SHORTLISTED, ApplicationStatus.WITHDRAWN}
    ),
    ApplicationStatus.SHORTLISTED: frozenset(
        {ApplicationStatus.PACK_READY, ApplicationStatus.WITHDRAWN}
    ),
    ApplicationStatus.PACK_READY: frozenset(
        {ApplicationStatus.APPROVED, ApplicationStatus.WITHDRAWN}
    ),
    ApplicationStatus.APPROVED: frozenset(
        {ApplicationStatus.FORM_STARTED, ApplicationStatus.WITHDRAWN}
    ),
    ApplicationStatus.FORM_STARTED: frozenset(
        {
            ApplicationStatus.NEEDS_INPUT,
            ApplicationStatus.NEEDS_USER_ACTION,
            ApplicationStatus.READY_TO_SUBMIT,
            ApplicationStatus.FAILED,
        }
    ),
    ApplicationStatus.NEEDS_INPUT: frozenset(
        {ApplicationStatus.FORM_STARTED, ApplicationStatus.WITHDRAWN}
    ),
    ApplicationStatus.NEEDS_USER_ACTION: frozenset(
        {ApplicationStatus.FORM_STARTED, ApplicationStatus.WITHDRAWN}
    ),
    ApplicationStatus.READY_TO_SUBMIT: frozenset(
        {ApplicationStatus.SUBMITTED, ApplicationStatus.FAILED, ApplicationStatus.WITHDRAWN}
    ),
    ApplicationStatus.SUBMITTED: frozenset({ApplicationStatus.CONFIRMED, ApplicationStatus.FAILED}),
    ApplicationStatus.CONFIRMED: frozenset(),
    ApplicationStatus.FAILED: frozenset({ApplicationStatus.FORM_STARTED}),
    ApplicationStatus.WITHDRAWN: frozenset(),
}

#: States that require a human before the workflow can move on (plan 7.6).
BLOCKING_STATES: frozenset[ApplicationStatus] = frozenset(
    {
        ApplicationStatus.NEEDS_INPUT,
        ApplicationStatus.NEEDS_USER_ACTION,
        ApplicationStatus.PACK_READY,
    }
)


class FactProvenance(StrEnum):
    """Plan section 7.1. Generated drafts are never stored as confirmed facts."""

    USER_CONFIRMED = "user_confirmed"
    CV_DERIVED = "cv_derived"
    GENERATED_DRAFT = "generated_draft"


class ResumeParseStatus(StrEnum):
    """Where an uploaded CV is in the ingestion pipeline."""

    UPLOADED = "uploaded"
    TEXT_EXTRACTED = "text_extracted"
    PARSED = "parsed"
    FAILED = "failed"


class FactKind(StrEnum):
    """The shapes a verified candidate fact can take.

    Kept closed so the match engine and the pack generator agree on what they
    are reading, and so an LLM cannot invent a new category of claim.
    """

    HEADLINE = "headline"
    SUMMARY = "summary"
    LOCATION = "location"
    YEARS_EXPERIENCE = "years_experience"
    SKILL = "skill"
    EMPLOYER = "employer"
    ROLE = "role"
    ACHIEVEMENT = "achievement"
    EDUCATION = "education"
    CERTIFICATION = "certification"
    LANGUAGE = "language"
    LINK = "link"


class RemoteType(StrEnum):
    ONSITE = "onsite"
    HYBRID = "hybrid"
    REMOTE = "remote"
    UNKNOWN = "unknown"


class Seniority(StrEnum):
    """Ordered coarsely; the match engine compares these, not raw titles."""

    INTERN = "intern"
    JUNIOR = "junior"
    MID = "mid"
    SENIOR = "senior"
    STAFF = "staff"
    LEAD = "lead"
    PRINCIPAL = "principal"
    MANAGER = "manager"
    DIRECTOR = "director"
    UNKNOWN = "unknown"


#: Rough ladder used for compatibility checks. Manager and director sit outside
#: the individual-contributor track, so they are deliberately not on it.
SENIORITY_RANK: dict[Seniority, int] = {
    Seniority.INTERN: 0,
    Seniority.JUNIOR: 1,
    Seniority.MID: 2,
    Seniority.SENIOR: 3,
    Seniority.STAFF: 4,
    Seniority.LEAD: 4,
    Seniority.PRINCIPAL: 5,
}


class DuplicateReason(StrEnum):
    """Which rule matched, in the order plan section 7.3 requires."""

    SOURCE_EXTERNAL_ID = "source_external_id"
    CANONICAL_URL = "canonical_url"
    COMPANY_TITLE_LOCATION = "company_title_location"
    CONTENT_FINGERPRINT = "content_fingerprint"


class MatchRouting(StrEnum):
    """Plan section 7.4 default routing."""

    HIGH_PRIORITY = "high_priority"
    NORMAL_REVIEW = "normal_review"
    POSSIBLE_MATCH = "possible_match"
    ARCHIVED = "archived"
    REJECTED = "rejected"


class ToolTier(StrEnum):
    """Plan section 7.8.

    The tier is authoritative on the server. A model asking for a tier it was
    not granted changes nothing: the registry re-checks before dispatch.
    """

    #: Executed automatically. Reads only, never mutates.
    READ = "t0_read"
    #: Requires an in-thread confirmation bound to the exact argument hash.
    PREPARE = "t1_prepare"
    #: Never callable from chat. The agent returns a deep link to the UI gate.
    EXTERNAL = "t2_external"


class ChatRole(StrEnum):
    SYSTEM = "system"
    USER = "user"
    ASSISTANT = "assistant"
    TOOL = "tool"


class ToolCallState(StrEnum):
    PENDING_CONFIRMATION = "pending_confirmation"
    RUNNING = "running"
    SUCCEEDED = "succeeded"
    FAILED = "failed"
    CANCELLED = "cancelled"
    REJECTED = "rejected"
    EXPIRED = "expired"
