"""Answer bank, fact provenance, and resume parse status.

Phase 1 of ``job-agent-plan.md``: the tables and columns that keep a generated
claim distinguishable from a confirmed one, and that let a re-parse run without
overwriting the user's corrections.

Revision ID: ff0c5584f0b7
Revises: 96ec4a138906
Create Date: 2026-08-30 06:14:32.502612+00:00
"""

from __future__ import annotations

from collections.abc import Sequence

import job_agent_domain.crypto
import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql
revision: str = 'ff0c5584f0b7'
down_revision: str | None = '96ec4a138906'
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.create_table('answer_bank',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('profile_id', sa.UUID(), nullable=False),
    sa.Column('question_key', sa.String(length=300), nullable=False),
    sa.Column('question', sa.Text(), nullable=False),
    sa.Column('answer', job_agent_domain.crypto.EncryptedText(), nullable=False),
    sa.Column('provenance', sa.String(length=30), nullable=False),
    sa.Column('confirmed_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('source_job_id', sa.UUID(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("provenance <> 'user_confirmed' OR confirmed_at IS NOT NULL", name='ck_answer_confirmed_has_timestamp'),
    sa.ForeignKeyConstraint(['profile_id'], ['candidate_profiles.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['source_job_id'], ['jobs.id'], ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('profile_id', 'question_key', name='uq_answer_profile_question')
    )
    op.create_index(op.f('ix_answer_bank_profile_id'), 'answer_bank', ['profile_id'], unique=False)
    op.add_column('candidate_facts', sa.Column('source_resume_id', sa.UUID(), nullable=True))
    op.add_column('candidate_facts', sa.Column('confirmed_at', sa.DateTime(timezone=True), nullable=True))
    op.add_column('candidate_facts', sa.Column('sort_order', sa.Integer(), nullable=False))
    op.create_index(op.f('ix_candidate_facts_kind'), 'candidate_facts', ['kind'], unique=False)
    op.create_index(op.f('ix_candidate_facts_provenance'), 'candidate_facts', ['provenance'], unique=False)
    op.create_foreign_key(None, 'candidate_facts', 'resume_files', ['source_resume_id'], ['id'], ondelete='SET NULL')
    op.create_check_constraint('ck_fact_confirmed_has_timestamp', 'candidate_facts', "provenance <> 'user_confirmed' OR confirmed_at IS NOT NULL")
    op.add_column('candidate_profiles', sa.Column('locked_fields', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False))
    op.add_column('resume_files', sa.Column('parse_status', sa.String(length=30), nullable=False))
    op.add_column('resume_files', sa.Column('parsed_at', sa.DateTime(timezone=True), nullable=True))
    op.add_column('resume_files', sa.Column('parse_error', sa.Text(), nullable=True))
    op.add_column('resume_files', sa.Column('is_primary', sa.Boolean(), nullable=False))
    op.alter_column('resume_files', 'extracted_text',
               existing_type=sa.TEXT(),
               type_=job_agent_domain.crypto.EncryptedText(),
               existing_nullable=True)


def downgrade() -> None:
    op.alter_column('resume_files', 'extracted_text',
               existing_type=job_agent_domain.crypto.EncryptedText(),
               type_=sa.TEXT(),
               existing_nullable=True)
    op.drop_column('resume_files', 'is_primary')
    op.drop_column('resume_files', 'parse_error')
    op.drop_column('resume_files', 'parsed_at')
    op.drop_column('resume_files', 'parse_status')
    op.drop_column('candidate_profiles', 'locked_fields')
    op.drop_constraint('ck_fact_confirmed_has_timestamp', 'candidate_facts', type_='check')
    op.drop_constraint(None, 'candidate_facts', type_='foreignkey')
    op.drop_index(op.f('ix_candidate_facts_provenance'), table_name='candidate_facts')
    op.drop_index(op.f('ix_candidate_facts_kind'), table_name='candidate_facts')
    op.drop_column('candidate_facts', 'sort_order')
    op.drop_column('candidate_facts', 'confirmed_at')
    op.drop_column('candidate_facts', 'source_resume_id')
    op.drop_index(op.f('ix_answer_bank_profile_id'), table_name='answer_bank')
    op.drop_table('answer_bank')
