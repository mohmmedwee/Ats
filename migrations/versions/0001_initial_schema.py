"""Initial schema.

Creates the pgvector extension and the Phase 0 table spine described in
``job-agent-plan.md`` section 8.

Revision ID: 96ec4a138906
Revises: 
Create Date: 2026-08-30 00:29:07.410711+00:00
"""

from __future__ import annotations

from collections.abc import Sequence

import pgvector.sqlalchemy
import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = '96ec4a138906'
down_revision: str | None = None
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.execute("CREATE EXTENSION IF NOT EXISTS vector")
    op.create_table('job_sources',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('kind', sa.String(length=50), nullable=False),
    sa.Column('name', sa.String(length=200), nullable=False),
    sa.Column('config', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('enabled', sa.Boolean(), nullable=False),
    sa.Column('auto_submit_allowed', sa.Boolean(), nullable=False),
    sa.Column('cursor', sa.String(length=500), nullable=True),
    sa.Column('last_run_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('last_error', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('kind', 'name', name='uq_source_kind_name')
    )
    op.create_table('users',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('email', sa.String(length=320), nullable=False),
    sa.Column('display_name', sa.String(length=200), nullable=False),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('email')
    )
    op.create_table('candidate_profiles',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('headline', sa.String(length=300), nullable=True),
    sa.Column('location', sa.String(length=200), nullable=True),
    sa.Column('years_experience', sa.Float(), nullable=True),
    sa.Column('preferences', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('version', sa.Integer(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('user_id')
    )
    op.create_table('chat_threads',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('title', sa.String(length=300), nullable=True),
    sa.Column('context', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('summary', sa.Text(), nullable=True),
    sa.Column('archived_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index(op.f('ix_chat_threads_user_id'), 'chat_threads', ['user_id'], unique=False)
    op.create_table('jobs',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('source_id', sa.UUID(), nullable=False),
    sa.Column('external_id', sa.String(length=300), nullable=False),
    sa.Column('company', sa.String(length=300), nullable=False),
    sa.Column('title', sa.String(length=300), nullable=False),
    sa.Column('location', sa.String(length=300), nullable=True),
    sa.Column('remote', sa.Boolean(), nullable=True),
    sa.Column('employment_type', sa.String(length=80), nullable=True),
    sa.Column('description', sa.Text(), nullable=False),
    sa.Column('application_url', sa.String(length=1000), nullable=False),
    sa.Column('canonical_url', sa.String(length=1000), nullable=True),
    sa.Column('compensation', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=True),
    sa.Column('content_hash', sa.String(length=64), nullable=False),
    sa.Column('posted_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('closes_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('fetched_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('injection_flagged', sa.Boolean(), nullable=False),
    sa.Column('embedding', pgvector.sqlalchemy.vector.VECTOR(dim=384), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['source_id'], ['job_sources.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('source_id', 'external_id', name='uq_job_source_external')
    )
    op.create_index('ix_job_company_title', 'jobs', ['company', 'title'], unique=False)
    op.create_index(op.f('ix_jobs_canonical_url'), 'jobs', ['canonical_url'], unique=False)
    op.create_index(op.f('ix_jobs_content_hash'), 'jobs', ['content_hash'], unique=False)
    op.create_table('resume_files',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('filename', sa.String(length=300), nullable=False),
    sa.Column('content_type', sa.String(length=100), nullable=False),
    sa.Column('byte_size', sa.Integer(), nullable=False),
    sa.Column('sha256', sa.String(length=64), nullable=False),
    sa.Column('storage_path', sa.String(length=1000), nullable=False),
    sa.Column('extracted_text', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('user_id', 'sha256', name='uq_resume_user_hash')
    )
    op.create_index(op.f('ix_resume_files_user_id'), 'resume_files', ['user_id'], unique=False)
    op.create_table('applications',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('job_id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('status', sa.String(length=40), nullable=False),
    sa.Column('approved_pack_hash', sa.String(length=64), nullable=True),
    sa.Column('submitted_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('confirmation_ref', sa.String(length=500), nullable=True),
    sa.Column('blocked_reason', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['job_id'], ['jobs.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('job_id', 'user_id', name='uq_application_job_user')
    )
    op.create_index(op.f('ix_applications_status'), 'applications', ['status'], unique=False)
    op.create_index(op.f('ix_applications_user_id'), 'applications', ['user_id'], unique=False)
    op.create_table('candidate_facts',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('profile_id', sa.UUID(), nullable=False),
    sa.Column('kind', sa.String(length=50), nullable=False),
    sa.Column('value', sa.Text(), nullable=False),
    sa.Column('provenance', sa.String(length=30), nullable=False),
    sa.Column('evidence_ref', sa.String(length=500), nullable=True),
    sa.Column('embedding', pgvector.sqlalchemy.vector.VECTOR(dim=384), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['profile_id'], ['candidate_profiles.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index(op.f('ix_candidate_facts_profile_id'), 'candidate_facts', ['profile_id'], unique=False)
    op.create_table('chat_messages',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('thread_id', sa.UUID(), nullable=False),
    sa.Column('role', sa.String(length=20), nullable=False),
    sa.Column('content', sa.Text(), nullable=False),
    sa.Column('citations', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('prompt_tokens', sa.Integer(), nullable=False),
    sa.Column('completion_tokens', sa.Integer(), nullable=False),
    sa.ForeignKeyConstraint(['thread_id'], ['chat_threads.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index(op.f('ix_chat_messages_thread_id'), 'chat_messages', ['thread_id'], unique=False)
    op.create_table('job_matches',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('job_id', sa.UUID(), nullable=False),
    sa.Column('profile_id', sa.UUID(), nullable=False),
    sa.Column('score', sa.Float(), nullable=False),
    sa.Column('routing', sa.String(length=30), nullable=False),
    sa.Column('breakdown', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('matched_requirements', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('missing_requirements', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('hard_blockers', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('explanation', sa.Text(), nullable=True),
    sa.Column('inputs_hash', sa.String(length=64), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('score >= 0 AND score <= 100', name='ck_match_score_range'),
    sa.ForeignKeyConstraint(['job_id'], ['jobs.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['profile_id'], ['candidate_profiles.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('job_id', 'profile_id', 'inputs_hash', name='uq_match_job_profile_inputs')
    )
    op.create_index(op.f('ix_job_matches_job_id'), 'job_matches', ['job_id'], unique=False)
    op.create_index(op.f('ix_job_matches_routing'), 'job_matches', ['routing'], unique=False)
    op.create_table('audit_events',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=True),
    sa.Column('action', sa.String(length=100), nullable=False),
    sa.Column('subject_type', sa.String(length=80), nullable=False),
    sa.Column('subject_id', sa.String(length=100), nullable=True),
    sa.Column('idempotency_key', sa.String(length=200), nullable=True),
    sa.Column('chat_thread_id', sa.UUID(), nullable=True),
    sa.Column('chat_message_id', sa.UUID(), nullable=True),
    sa.Column('payload', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.ForeignKeyConstraint(['chat_message_id'], ['chat_messages.id'], ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['chat_thread_id'], ['chat_threads.id'], ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('idempotency_key')
    )
    op.create_index(op.f('ix_audit_events_action'), 'audit_events', ['action'], unique=False)
    op.create_index(op.f('ix_audit_events_created_at'), 'audit_events', ['created_at'], unique=False)
    op.create_table('chat_tool_calls',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('thread_id', sa.UUID(), nullable=False),
    sa.Column('message_id', sa.UUID(), nullable=False),
    sa.Column('tool_name', sa.String(length=100), nullable=False),
    sa.Column('tier', sa.String(length=30), nullable=False),
    sa.Column('arguments', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.Column('args_hash', sa.String(length=64), nullable=False),
    sa.Column('state', sa.String(length=30), nullable=False),
    sa.Column('idempotency_key', sa.String(length=200), nullable=False),
    sa.Column('confirmed_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('expires_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('result', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=True),
    sa.Column('error', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("tier <> 't2_external'", name='ck_chat_tool_call_no_external_tier'),
    sa.ForeignKeyConstraint(['message_id'], ['chat_messages.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['thread_id'], ['chat_threads.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('idempotency_key')
    )
    op.create_index(op.f('ix_chat_tool_calls_state'), 'chat_tool_calls', ['state'], unique=False)
    op.create_index(op.f('ix_chat_tool_calls_thread_id'), 'chat_tool_calls', ['thread_id'], unique=False)


def downgrade() -> None:
    op.drop_index(op.f('ix_chat_tool_calls_thread_id'), table_name='chat_tool_calls')
    op.drop_index(op.f('ix_chat_tool_calls_state'), table_name='chat_tool_calls')
    op.drop_table('chat_tool_calls')
    op.drop_index(op.f('ix_audit_events_created_at'), table_name='audit_events')
    op.drop_index(op.f('ix_audit_events_action'), table_name='audit_events')
    op.drop_table('audit_events')
    op.drop_index(op.f('ix_job_matches_routing'), table_name='job_matches')
    op.drop_index(op.f('ix_job_matches_job_id'), table_name='job_matches')
    op.drop_table('job_matches')
    op.drop_index(op.f('ix_chat_messages_thread_id'), table_name='chat_messages')
    op.drop_table('chat_messages')
    op.drop_index(op.f('ix_candidate_facts_profile_id'), table_name='candidate_facts')
    op.drop_table('candidate_facts')
    op.drop_index(op.f('ix_applications_user_id'), table_name='applications')
    op.drop_index(op.f('ix_applications_status'), table_name='applications')
    op.drop_table('applications')
    op.drop_index(op.f('ix_resume_files_user_id'), table_name='resume_files')
    op.drop_table('resume_files')
    op.drop_index(op.f('ix_jobs_content_hash'), table_name='jobs')
    op.drop_index(op.f('ix_jobs_canonical_url'), table_name='jobs')
    op.drop_index('ix_job_company_title', table_name='jobs')
    op.drop_table('jobs')
    op.drop_index(op.f('ix_chat_threads_user_id'), table_name='chat_threads')
    op.drop_table('chat_threads')
    op.drop_table('candidate_profiles')
    op.drop_table('users')
    op.drop_table('job_sources')
