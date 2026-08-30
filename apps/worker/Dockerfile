# The worker shares the API image contents; only the entrypoint differs.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy

COPY --from=ghcr.io/astral-sh/uv:0.5.11 /uv /usr/local/bin/uv

WORKDIR /app

COPY pyproject.toml uv.lock ./
COPY packages/ ./packages/
COPY apps/api/pyproject.toml ./apps/api/
COPY apps/worker/pyproject.toml ./apps/worker/
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --all-packages --no-dev

COPY apps/ ./apps/
RUN --mount=type=cache,target=/root/.cache/uv uv sync --frozen --all-packages --no-dev

ENV PATH="/app/.venv/bin:${PATH}"

RUN useradd --create-home --uid 10001 appuser && chown -R appuser /app
USER appuser

CMD ["dramatiq", "job_agent_worker.actors", "--processes", "2", "--threads", "4"]
