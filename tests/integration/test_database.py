"""Schema-level guarantees. Requires PostgreSQL with pgvector.

Run with: pytest -m integration
"""

from __future__ import annotations

import uuid

import pytest
from job_agent_domain.db import get_sessionmaker
from job_agent_domain.models import Job, JobSource, User
from sqlalchemy import text
from sqlalchemy.exc import IntegrityError

pytestmark = pytest.mark.integration


async def test_pgvector_extension_is_installed() -> None:
    async with get_sessionmaker()() as session:
        result = await session.execute(
            text("SELECT extname FROM pg_extension WHERE extname='vector'")
        )
        assert result.scalar_one() == "vector"


async def test_migration_created_every_expected_table() -> None:
    expected = {
        "users",
        "candidate_profiles",
        "candidate_facts",
        "resume_files",
        "job_sources",
        "jobs",
        "job_matches",
        "applications",
        "audit_events",
        "chat_threads",
        "chat_messages",
        "chat_tool_calls",
    }
    async with get_sessionmaker()() as session:
        result = await session.execute(
            text("SELECT tablename FROM pg_tables WHERE schemaname='public'")
        )
        assert expected <= set(result.scalars())


async def test_rediscovering_the_same_job_cannot_duplicate_it() -> None:
    """Plan Phase 2 acceptance: re-running discovery creates no duplicates."""
    sessionmaker = get_sessionmaker()
    async with sessionmaker() as session:
        source = JobSource(kind="greenhouse", name=f"acme-{uuid.uuid4().hex[:8]}")
        session.add(source)
        await session.flush()

        def _job() -> Job:
            return Job(
                source_id=source.id,
                external_id="ext-1",
                company="Acme",
                title="Engineering Lead",
                description="Lead the backend team.",
                application_url="https://boards.example.com/acme/ext-1",
                content_hash="a" * 64,
            )

        session.add(_job())
        await session.flush()
        session.add(_job())
        with pytest.raises(IntegrityError):
            await session.flush()
        await session.rollback()


async def test_audit_idempotency_key_is_unique() -> None:
    from job_agent_domain.models import AuditEvent

    sessionmaker = get_sessionmaker()
    key = f"key-{uuid.uuid4()}"
    async with sessionmaker() as session:
        session.add(
            AuditEvent(action="test.action", subject_type="test", idempotency_key=key, payload={})
        )
        await session.flush()
        session.add(
            AuditEvent(action="test.action", subject_type="test", idempotency_key=key, payload={})
        )
        with pytest.raises(IntegrityError):
            await session.flush()
        await session.rollback()


async def test_database_rejects_an_external_tier_tool_call_row() -> None:
    """The T2 ban is enforced in the schema, not only in Python."""
    sessionmaker = get_sessionmaker()
    async with sessionmaker() as session:
        user = User(email=f"{uuid.uuid4().hex}@example.com", display_name="Test")
        session.add(user)
        await session.flush()

        await session.execute(
            text(
                "INSERT INTO chat_threads (id, user_id, context, created_at, updated_at) "
                "VALUES (:id, :user_id, '{}', now(), now())"
            ),
            {"id": (thread_id := uuid.uuid4()), "user_id": user.id},
        )
        await session.execute(
            text(
                "INSERT INTO chat_messages (id, thread_id, role, content, citations, "
                "prompt_tokens, completion_tokens, created_at) "
                "VALUES (:id, :thread_id, 'assistant', 'x', '[]', 0, 0, now())"
            ),
            {"id": (message_id := uuid.uuid4()), "thread_id": thread_id},
        )
        with pytest.raises(IntegrityError):
            await session.execute(
                text(
                    "INSERT INTO chat_tool_calls (id, thread_id, message_id, tool_name, tier, "
                    "arguments, args_hash, state, idempotency_key, created_at, updated_at) "
                    "VALUES (:id, :thread_id, :message_id, 'submit_application', 't2_external', "
                    "'{}', 'h', 'running', :key, now(), now())"
                ),
                {
                    "id": uuid.uuid4(),
                    "thread_id": thread_id,
                    "message_id": message_id,
                    "key": f"k-{uuid.uuid4()}",
                },
            )
        await session.rollback()
