FROM python:3.12-slim AS base

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy

COPY --from=ghcr.io/astral-sh/uv:0.5.11 /uv /usr/local/bin/uv

WORKDIR /app

# Manifests first so a source-only change does not reinstall dependencies.
COPY pyproject.toml uv.lock alembic.ini ./
COPY packages/ ./packages/
COPY apps/api/pyproject.toml ./apps/api/
COPY apps/worker/pyproject.toml ./apps/worker/
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --all-packages --no-dev

COPY migrations/ ./migrations/
COPY apps/api/ ./apps/api/
COPY apps/worker/ ./apps/worker/
COPY scripts/ ./scripts/
RUN --mount=type=cache,target=/root/.cache/uv uv sync --frozen --all-packages --no-dev

ENV PATH="/app/.venv/bin:${PATH}"

RUN useradd --create-home --uid 10001 appuser && chown -R appuser /app
USER appuser

EXPOSE 8000
CMD ["/app/scripts/start-api.sh"]
