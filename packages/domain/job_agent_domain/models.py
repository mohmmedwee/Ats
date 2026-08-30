"""SQLAlchemy models for the tables listed in ``job-agent-plan.md`` section 8.

Phase 0 creates the spine: identity, profile, sources, jobs, matches,
applications, audit, and chat. Later phases add the remaining tables.

Two invariants are enforced in the schema rather than in application code,
because they are the ones that must not be bypassed:

* ``jobs`` is unique on ``(source_id, external_id)`` so re-running discovery
  cannot create duplicates.
* every external action carries a unique idempotency key.
"""

from __future__ import annotations

import uuid
from datetime import datetime
from typing import Any, ClassVar

from pgvector.sqlalchemy import Vector
from sqlalchemy import (
    JSON,
    Boolean,
    CheckConstraint,
    DateTime,
    Float,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship

from job_agent_domain.columns import StrEnumType
from job_agent_domain.crypto import EncryptedText
from job_agent_domain.enums import (
    ApplicationStatus,
    ChatRole,
    FactKind,
    FactProvenance,
    MatchRouting,
    ResumeParseStatus,
    ToolCallState,
    ToolTier,
)

#: Dimensionality of ``all-MiniLM-L6-v2``. Changing the embedding model requires
#: a migration, so the value is pinned here rather than read from settings.
EMBEDDING_DIM = 384

JSONType = JSONB().with_variant(JSON(), "sqlite")


class Base(DeclarativeBase):
    type_annotation_map: ClassVar[dict[Any, Any]] = {
        dict[str, Any]: JSONType,
        list[str]: JSONType,
    }


def _uuid_pk() -> Mapped[uuid.UUID]:
    return mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)


class TimestampMixin:
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )


class User(Base, TimestampMixin):
    __tablename__ = "users"

    id: Mapped[uuid.UUID] = _uuid_pk()
    email: Mapped[str] = mapped_column(String(320), unique=True, nullable=False)
    display_name: Mapped[str] = mapped_column(String(200), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    profile: Mapped[CandidateProfile | None] = relationship(back_populates="user", uselist=False)


class CandidateProfile(Base, TimestampMixin):
    __tablename__ = "candidate_profiles"

    id: Mapped[uuid.UUID] = _uuid_pk()
    user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), unique=True, nullable=False
    )
    headline: Mapped[str | None] = mapped_column(String(300))
    location: Mapped[str | None] = mapped_column(String(200))
    years_experience: Mapped[float | None] = mapped_column(Float)
    #: Structured answers to the onboarding questions in plan section 2.
    preferences: Mapped[dict[str, Any]] = mapped_column(JSONType, default=dict, nullable=False)
    #: Bumped on every user edit so generated artifacts can pin the version they used.
    version: Mapped[int] = mapped_column(Integer, default=1, nullable=False)
    #: Profile fields the user edited by hand. Reprocessing a CV skips these, so
    #: a correction is never silently undone by a re-parse (Phase 1 acceptance).
    locked_fields: Mapped[list[str]] = mapped_column(JSONType, default=list, nullable=False)

    user: Mapped[User] = relationship(back_populates="profile")
    facts: Mapped[list[CandidateFact]] = relationship(back_populates="profile")
    answers: Mapped[list[AnswerBankEntry]] = relationship(back_populates="profile")


class CandidateFact(Base, TimestampMixin):
    """A single verifiable claim. Generated wording never lands here as confirmed."""

    __tablename__ = "candidate_facts"

    id: Mapped[uuid.UUID] = _uuid_pk()
    profile_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("candidate_profiles.id", ondelete="CASCADE"), nullable=False, index=True
    )
    kind: Mapped[FactKind] = mapped_column(StrEnumType(FactKind, 50), nullable=False, index=True)
    value: Mapped[str] = mapped_column(Text, nullable=False)
    provenance: Mapped[FactProvenance] = mapped_column(
        StrEnumType(FactProvenance, 30), nullable=False, index=True
    )
    #: Points back into the immutable resume snapshot the fact was taken from.
    evidence_ref: Mapped[str | None] = mapped_column(String(500))
    source_resume_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("resume_files.id", ondelete="SET NULL")
    )
    #: Set only when the user explicitly confirms. A generated draft never gets
    #: this, which is what keeps an inferred claim out of an application.
    confirmed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    sort_order: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    embedding: Mapped[list[float] | None] = mapped_column(Vector(EMBEDDING_DIM))

    profile: Mapped[CandidateProfile] = relationship(back_populates="facts")

    __table_args__ = (
        CheckConstraint(
            "provenance <> 'user_confirmed' OR confirmed_at IS NOT NULL",
            name="ck_fact_confirmed_has_timestamp",
        ),
    )


class ResumeFile(Base, TimestampMixin):
    """Immutable source evidence. Never rewritten, only superseded."""

    __tablename__ = "resume_files"

    id: Mapped[uuid.UUID] = _uuid_pk()
    user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    filename: Mapped[str] = mapped_column(String(300), nullable=False)
    content_type: Mapped[str] = mapped_column(String(100), nullable=False)
    byte_size: Mapped[int] = mapped_column(Integer, nullable=False)
    sha256: Mapped[str] = mapped_column(String(64), nullable=False)
    storage_path: Mapped[str] = mapped_column(String(1000), nullable=False)
    #: Encrypted at rest by the column type (plan section 10).
    extracted_text: Mapped[str | None] = mapped_column(EncryptedText)
    parse_status: Mapped[ResumeParseStatus] = mapped_column(
        StrEnumType(ResumeParseStatus, 30), default=ResumeParseStatus.UPLOADED, nullable=False
    )
    parsed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    parse_error: Mapped[str | None] = mapped_column(Text)
    #: True for the CV that represents the candidate right now.
    is_primary: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    __table_args__ = (UniqueConstraint("user_id", "sha256", name="uq_resume_user_hash"),)


class AnswerBankEntry(Base, TimestampMixin):
    """Reusable answers to recurring application questions (plan section 7.1).

    An answer is only offered to a form when the user has confirmed it. Drafts
    live here too, clearly marked, so the review queue can show what still needs
    a decision instead of quietly filling something in.
    """

    __tablename__ = "answer_bank"

    id: Mapped[uuid.UUID] = _uuid_pk()
    profile_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("candidate_profiles.id", ondelete="CASCADE"), nullable=False, index=True
    )
    #: Normalised form of the question, used for lookup across differently
    #: worded versions of the same ask.
    question_key: Mapped[str] = mapped_column(String(300), nullable=False)
    question: Mapped[str] = mapped_column(Text, nullable=False)
    answer: Mapped[str] = mapped_column(EncryptedText, nullable=False)
    provenance: Mapped[FactProvenance] = mapped_column(
        StrEnumType(FactProvenance, 30), nullable=False
    )
    confirmed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    #: Where the question was first seen, for context when reviewing.
    source_job_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("jobs.id", ondelete="SET NULL")
    )

    profile: Mapped[CandidateProfile] = relationship(back_populates="answers")

    __table_args__ = (
        UniqueConstraint("profile_id", "question_key", name="uq_answer_profile_question"),
        CheckConstraint(
            "provenance <> 'user_confirmed' OR confirmed_at IS NOT NULL",
            name="ck_answer_confirmed_has_timestamp",
        ),
    )


class JobSource(Base, TimestampMixin):
    __tablename__ = "job_sources"

    id: Mapped[uuid.UUID] = _uuid_pk()
    kind: Mapped[str] = mapped_column(String(50), nullable=False)
    name: Mapped[str] = mapped_column(String(200), nullable=False)
    config: Mapped[dict[str, Any]] = mapped_column(JSONType, default=dict, nullable=False)
    enabled: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    #: Auto-submit allow-listing is per source and off by default (plan section 4).
    auto_submit_allowed: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    cursor: Mapped[str | None] = mapped_column(String(500))
    last_run_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    last_error: Mapped[str | None] = mapped_column(Text)

    __table_args__ = (UniqueConstraint("kind", "name", name="uq_source_kind_name"),)


class Job(Base, TimestampMixin):
    __tablename__ = "jobs"

    id: Mapped[uuid.UUID] = _uuid_pk()
    source_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("job_sources.id", ondelete="CASCADE"), nullable=False
    )
    external_id: Mapped[str] = mapped_column(String(300), nullable=False)
    company: Mapped[str] = mapped_column(String(300), nullable=False)
    title: Mapped[str] = mapped_column(String(300), nullable=False)
    location: Mapped[str | None] = mapped_column(String(300))
    remote: Mapped[bool | None] = mapped_column(Boolean)
    employment_type: Mapped[str | None] = mapped_column(String(80))
    description: Mapped[str] = mapped_column(Text, nullable=False)
    application_url: Mapped[str] = mapped_column(String(1000), nullable=False)
    canonical_url: Mapped[str | None] = mapped_column(String(1000), index=True)
    compensation: Mapped[dict[str, Any] | None] = mapped_column(JSONType)
    content_hash: Mapped[str] = mapped_column(String(64), nullable=False, index=True)
    posted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    closes_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    fetched_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    #: Set when a hostile posting attempts prompt injection (plan 7.8).
    injection_flagged: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    embedding: Mapped[list[float] | None] = mapped_column(Vector(EMBEDDING_DIM))

    __table_args__ = (
        UniqueConstraint("source_id", "external_id", name="uq_job_source_external"),
        Index("ix_job_company_title", "company", "title"),
    )


class JobMatch(Base, TimestampMixin):
    __tablename__ = "job_matches"

    id: Mapped[uuid.UUID] = _uuid_pk()
    job_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("jobs.id", ondelete="CASCADE"), nullable=False, index=True
    )
    profile_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("candidate_profiles.id", ondelete="CASCADE"), nullable=False
    )
    score: Mapped[float] = mapped_column(Float, nullable=False)
    routing: Mapped[MatchRouting] = mapped_column(
        StrEnumType(MatchRouting, 30), nullable=False, index=True
    )
    #: Per-dimension breakdown using the weights in plan section 7.4.
    breakdown: Mapped[dict[str, Any]] = mapped_column(JSONType, default=dict, nullable=False)
    matched_requirements: Mapped[dict[str, Any]] = mapped_column(
        JSONType, default=dict, nullable=False
    )
    missing_requirements: Mapped[dict[str, Any]] = mapped_column(
        JSONType, default=dict, nullable=False
    )
    hard_blockers: Mapped[dict[str, Any]] = mapped_column(JSONType, default=dict, nullable=False)
    explanation: Mapped[str | None] = mapped_column(Text)
    #: Same inputs must reproduce the same score (plan Phase 3 acceptance).
    inputs_hash: Mapped[str] = mapped_column(String(64), nullable=False)

    __table_args__ = (
        UniqueConstraint("job_id", "profile_id", "inputs_hash", name="uq_match_job_profile_inputs"),
        CheckConstraint("score >= 0 AND score <= 100", name="ck_match_score_range"),
    )


class Application(Base, TimestampMixin):
    __tablename__ = "applications"

    id: Mapped[uuid.UUID] = _uuid_pk()
    job_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("jobs.id", ondelete="CASCADE"), nullable=False
    )
    user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    status: Mapped[ApplicationStatus] = mapped_column(
        StrEnumType(ApplicationStatus, 40), nullable=False, index=True
    )
    #: Hash of the exact pack the user approved. A submit token is bound to it.
    approved_pack_hash: Mapped[str | None] = mapped_column(String(64))
    submitted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    confirmation_ref: Mapped[str | None] = mapped_column(String(500))
    blocked_reason: Mapped[str | None] = mapped_column(Text)

    __table_args__ = (UniqueConstraint("job_id", "user_id", name="uq_application_job_user"),)


class AuditEvent(Base):
    """Immutable. Never updated, never soft-deleted (plan section 8)."""

    __tablename__ = "audit_events"

    id: Mapped[uuid.UUID] = _uuid_pk()
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False, index=True
    )
    user_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    action: Mapped[str] = mapped_column(String(100), nullable=False, index=True)
    subject_type: Mapped[str] = mapped_column(String(80), nullable=False)
    subject_id: Mapped[str | None] = mapped_column(String(100))
    #: Present for anything that touches the outside world; unique so a retried
    #: external action can never fire twice.
    idempotency_key: Mapped[str | None] = mapped_column(String(200), unique=True)
    #: Set when the action originated in chat, so a change is traceable to the turn.
    chat_thread_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("chat_threads.id", ondelete="SET NULL")
    )
    chat_message_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("chat_messages.id", ondelete="SET NULL")
    )
    payload: Mapped[dict[str, Any]] = mapped_column(JSONType, default=dict, nullable=False)


class ChatThread(Base, TimestampMixin):
    __tablename__ = "chat_threads"

    id: Mapped[uuid.UUID] = _uuid_pk()
    user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    title: Mapped[str | None] = mapped_column(String(300))
    #: Entity the panel was docked to, e.g. {"job_id": "..."}.
    context: Mapped[dict[str, Any]] = mapped_column(JSONType, default=dict, nullable=False)
    #: Rolling summary used to compact long threads without losing pinned facts.
    summary: Mapped[str | None] = mapped_column(Text)
    archived_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    messages: Mapped[list[ChatMessage]] = relationship(back_populates="thread")


class ChatMessage(Base):
    """Append-only. An edit creates a new message rather than mutating history."""

    __tablename__ = "chat_messages"

    id: Mapped[uuid.UUID] = _uuid_pk()
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    thread_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("chat_threads.id", ondelete="CASCADE"), nullable=False, index=True
    )
    role: Mapped[ChatRole] = mapped_column(StrEnumType(ChatRole, 20), nullable=False)
    content: Mapped[str] = mapped_column(Text, nullable=False)
    #: Resolvable references such as ``job:<uuid>`` rendered as chips in the UI.
    citations: Mapped[list[str]] = mapped_column(JSONType, default=list, nullable=False)
    prompt_tokens: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    completion_tokens: Mapped[int] = mapped_column(Integer, default=0, nullable=False)

    thread: Mapped[ChatThread] = relationship(back_populates="messages")
    tool_calls: Mapped[list[ChatToolCall]] = relationship(back_populates="message")


class ChatToolCall(Base, TimestampMixin):
    __tablename__ = "chat_tool_calls"

    id: Mapped[uuid.UUID] = _uuid_pk()
    thread_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("chat_threads.id", ondelete="CASCADE"), nullable=False, index=True
    )
    message_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("chat_messages.id", ondelete="CASCADE"), nullable=False
    )
    tool_name: Mapped[str] = mapped_column(String(100), nullable=False)
    #: Recorded from the registry at dispatch time, not from the model's request.
    tier: Mapped[ToolTier] = mapped_column(StrEnumType(ToolTier, 30), nullable=False)
    arguments: Mapped[dict[str, Any]] = mapped_column(JSONType, default=dict, nullable=False)
    #: A confirmation is bound to this hash; changed arguments need a new card.
    args_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    state: Mapped[ToolCallState] = mapped_column(
        StrEnumType(ToolCallState, 30), nullable=False, index=True
    )
    idempotency_key: Mapped[str] = mapped_column(String(200), unique=True, nullable=False)
    confirmed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    result: Mapped[dict[str, Any] | None] = mapped_column(JSONType)
    error: Mapped[str | None] = mapped_column(Text)

    message: Mapped[ChatMessage] = relationship(back_populates="tool_calls")

    __table_args__ = (
        CheckConstraint("tier <> 't2_external'", name="ck_chat_tool_call_no_external_tier"),
    )
