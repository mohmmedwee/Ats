#!/usr/bin/env sh
# Migrations run before the API accepts traffic, so a fresh checkout comes up
# with a correct schema in one command (Phase 0 acceptance criterion).
set -eu

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  echo "applying database migrations"
  alembic upgrade head
fi

exec uvicorn job_agent_api.main:app --host "${API_HOST:-0.0.0.0}" --port "${API_PORT:-8000}"
