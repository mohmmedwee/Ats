"""Discovery: snapshots, companies, and normalised job fields.

Phase 2 of ``job-agent-plan.md``: the columns normalisation writes, the table
that keeps every fetch traceable, and the per-source rate limit and backoff
state that stops one failing board from taking the whole run down.

Revision ID: 6dbf7d8155f9
Revises: ff0c5584f0b7
Create Date: 2026-08-30 07:18:04.313589+00:00
"""

from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql
revision: str = '6dbf7d8155f9'
down_revision: str | None = 'ff0c5584f0b7'
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.create_table('companies',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('name', sa.String(length=300), nullable=False),
    sa.Column('normalized_name', sa.String(length=300), nullable=False),
    sa.Column('website', sa.String(length=500), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('normalized_name')
    )
    op.create_index('ix_company_normalized_name', 'companies', ['normalized_name'], unique=False)
    op.create_table('job_raw_snapshots',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('job_id', sa.UUID(), nullable=True),
    sa.Column('source_id', sa.UUID(), nullable=False),
    sa.Column('external_id', sa.String(length=300), nullable=False),
    sa.Column('source_url', sa.String(length=1000), nullable=False),
    sa.Column('fetched_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('content_hash', sa.String(length=64), nullable=False),
    sa.Column('payload', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False),
    sa.ForeignKeyConstraint(['job_id'], ['jobs.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['source_id'], ['job_sources.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('source_id', 'external_id', 'content_hash', name='uq_snapshot_source_ext_hash')
    )
    op.create_index(op.f('ix_job_raw_snapshots_job_id'), 'job_raw_snapshots', ['job_id'], unique=False)
    op.add_column('job_sources', sa.Column('last_success_at', sa.DateTime(timezone=True), nullable=True))
    op.add_column('job_sources', sa.Column('rate_limit_per_minute', sa.Integer(), nullable=False))
    op.add_column('job_sources', sa.Column('consecutive_failures', sa.Integer(), nullable=False))
    op.add_column('job_sources', sa.Column('paused_until', sa.DateTime(timezone=True), nullable=True))
    op.add_column('jobs', sa.Column('company_id', sa.UUID(), nullable=True))
    op.add_column('jobs', sa.Column('normalized_title', sa.String(length=300), nullable=True))
    op.add_column('jobs', sa.Column('seniority', sa.String(length=30), nullable=False))
    op.add_column('jobs', sa.Column('country', sa.String(length=100), nullable=True))
    op.add_column('jobs', sa.Column('city', sa.String(length=150), nullable=True))
    op.add_column('jobs', sa.Column('remote_type', sa.String(length=20), nullable=False))
    op.add_column('jobs', sa.Column('required_skills', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False))
    op.add_column('jobs', sa.Column('preferred_skills', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False))
    op.add_column('jobs', sa.Column('responsibilities', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False))
    op.add_column('jobs', sa.Column('visa_sponsorship', sa.Boolean(), nullable=True))
    op.add_column('jobs', sa.Column('fingerprint', sa.String(length=64), nullable=True))
    op.add_column('jobs', sa.Column('possible_duplicate_of', sa.UUID(), nullable=True))
    op.add_column('jobs', sa.Column('duplicate_reason', sa.String(length=40), nullable=True))
    op.add_column('jobs', sa.Column('duplicate_confidence', sa.Float(), nullable=True))
    op.add_column('jobs', sa.Column('injection_signals', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False))
    op.create_index('ix_job_dedup', 'jobs', ['company', 'normalized_title', 'location'], unique=False)
    op.create_index(op.f('ix_jobs_company_id'), 'jobs', ['company_id'], unique=False)
    op.create_index(op.f('ix_jobs_fingerprint'), 'jobs', ['fingerprint'], unique=False)
    op.create_index(op.f('ix_jobs_normalized_title'), 'jobs', ['normalized_title'], unique=False)
    op.create_foreign_key(None, 'jobs', 'jobs', ['possible_duplicate_of'], ['id'], ondelete='SET NULL')
    op.create_foreign_key(None, 'jobs', 'companies', ['company_id'], ['id'], ondelete='SET NULL')
    op.drop_column('jobs', 'remote')


def downgrade() -> None:
    op.add_column('jobs', sa.Column('remote', sa.BOOLEAN(), autoincrement=False, nullable=True))
    op.drop_constraint(None, 'jobs', type_='foreignkey')
    op.drop_constraint(None, 'jobs', type_='foreignkey')
    op.drop_index(op.f('ix_jobs_normalized_title'), table_name='jobs')
    op.drop_index(op.f('ix_jobs_fingerprint'), table_name='jobs')
    op.drop_index(op.f('ix_jobs_company_id'), table_name='jobs')
    op.drop_index('ix_job_dedup', table_name='jobs')
    op.drop_column('jobs', 'injection_signals')
    op.drop_column('jobs', 'duplicate_confidence')
    op.drop_column('jobs', 'duplicate_reason')
    op.drop_column('jobs', 'possible_duplicate_of')
    op.drop_column('jobs', 'fingerprint')
    op.drop_column('jobs', 'visa_sponsorship')
    op.drop_column('jobs', 'responsibilities')
    op.drop_column('jobs', 'preferred_skills')
    op.drop_column('jobs', 'required_skills')
    op.drop_column('jobs', 'remote_type')
    op.drop_column('jobs', 'city')
    op.drop_column('jobs', 'country')
    op.drop_column('jobs', 'seniority')
    op.drop_column('jobs', 'normalized_title')
    op.drop_column('jobs', 'company_id')
    op.drop_column('job_sources', 'paused_until')
    op.drop_column('job_sources', 'consecutive_failures')
    op.drop_column('job_sources', 'rate_limit_per_minute')
    op.drop_column('job_sources', 'last_success_at')
    op.drop_index(op.f('ix_job_raw_snapshots_job_id'), table_name='job_raw_snapshots')
    op.drop_table('job_raw_snapshots')
    op.drop_index('ix_company_normalized_name', table_name='companies')
    op.drop_table('companies')
