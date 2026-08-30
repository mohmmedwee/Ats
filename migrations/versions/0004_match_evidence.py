"""Match evidence and score signals.

Phase 3 of ``job-agent-plan.md``: the rows behind a score, so a matched or
missing requirement can be queried rather than only rendered.

Revision ID: 22b6ff141a7f
Revises: 6dbf7d8155f9
Create Date: 2026-08-30 14:29:43.887163+00:00
"""

from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql
revision: str = '22b6ff141a7f'
down_revision: str | None = '6dbf7d8155f9'
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.create_table('match_evidence',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('match_id', sa.UUID(), nullable=False),
    sa.Column('kind', sa.String(length=40), nullable=False),
    sa.Column('dimension', sa.String(length=60), nullable=False),
    sa.Column('requirement', sa.Text(), nullable=False),
    sa.Column('reference', sa.String(length=300), nullable=True),
    sa.Column('detail', sa.Text(), nullable=True),
    sa.Column('source', sa.String(length=10), nullable=False),
    sa.ForeignKeyConstraint(['match_id'], ['job_matches.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index(op.f('ix_match_evidence_kind'), 'match_evidence', ['kind'], unique=False)
    op.create_index(op.f('ix_match_evidence_match_id'), 'match_evidence', ['match_id'], unique=False)
    op.add_column('job_matches', sa.Column('explanation_data', postgresql.JSONB(astext_type=sa.Text()).with_variant(sa.JSON(), 'sqlite'), nullable=False))
    op.add_column('job_matches', sa.Column('semantic_similarity', sa.Float(), nullable=True))
    op.add_column('job_matches', sa.Column('embedding_model', sa.String(length=200), nullable=True))


def downgrade() -> None:
    op.drop_column('job_matches', 'embedding_model')
    op.drop_column('job_matches', 'semantic_similarity')
    op.drop_column('job_matches', 'explanation_data')
    op.drop_index(op.f('ix_match_evidence_match_id'), table_name='match_evidence')
    op.drop_index(op.f('ix_match_evidence_kind'), table_name='match_evidence')
    op.drop_table('match_evidence')
