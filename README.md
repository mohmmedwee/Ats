# Job Agent

A self-hosted agent that discovers relevant roles, scores them against your CV,
prepares tailored application materials, fills supported application forms, and
tracks every application — pausing for your approval before anything reaches an
employer.

The full specification is [`job-agent-plan.md`](job-agent-plan.md). Development
follows its phases in order.

## Status

**Phase 0 (specification and scaffold) is complete.** What runs today:

| Piece | State |
|---|---|
| Monorepo, tooling, CI | done |
| Database schema and migrations | done, spine tables from plan section 8 |
| API skeleton, health/readiness, policy endpoint | done |
| Chat tool registry and tier enforcement | done, with handlers arriving in Phase 8 |
| AI provider interface | done, Ollama / MLX / OpenAI-compatible / fake |
| Worker, broker, timezone-aware scheduler | done, actors arrive with their phases |
| Web app shell | done, dashboard and chat-policy views |
| CV ingestion, discovery, matching, applications | not started (Phases 1-7) |

## What it will not do

These are design constraints, not defaults:

- It never submits an application without your explicit approval of the exact
  materials.
- The chat agent has no tool that can submit, start a form, or change a policy.
  Attempting to register one raises; the database rejects the row as well.
- It never invents an employer, date, skill, metric, or certification that is
  not in your verified facts.
- It does not bypass CAPTCHA or OTP, and it does not scrape authenticated
  LinkedIn pages.

## Quick start

Requires Docker and Docker Compose.

```bash
cp .env.example .env
python scripts/generate_keys.py   # paste the output into .env
make up
```

- API: <http://localhost:8000/health>, docs at <http://localhost:8000/docs>
- Web: <http://localhost:5173>

Migrations run automatically when the API container starts.

## Local development without Docker

Requires Python 3.12, Node 22, PostgreSQL 16 with pgvector, and Redis.

```bash
make install          # uv sync --all-packages + npm install
cp .env.example .env  # point DATABASE_URL / REDIS_URL at your services
make migrate
make api              # http://localhost:8000
make worker           # in another shell
make web              # http://localhost:5173
```

## Verification

```bash
make check            # ruff, mypy, pytest, eslint, vue-tsc, vitest
uv run pytest -m "not integration"   # no database required
uv run pytest -m integration         # requires PostgreSQL + Redis
```

Tests never contact a model provider (`AI_PROVIDER=fake`) and never submit an
application anywhere.

## Layout

```text
apps/api        FastAPI application
apps/worker     Dramatiq actors and the APScheduler schedule
apps/web        Vue 3 + TypeScript front end
packages/domain           models, enums, settings, state machine
packages/ai               provider interface, structured output, fake provider
packages/chat             tool registry, tiers, prompt zones, injection scanner
packages/connectors       job source contract (adapters in Phase 2)
packages/matching         scoring (Phase 3)
packages/application_automation  ATS adapters and form filling (Phase 5)
packages/observability    structured logging and PII redaction
migrations      Alembic revisions
docs/adr        architecture decision records
```

## Configuration

Every setting is an environment variable; see [`.env.example`](.env.example).
The ones that change behaviour most:

| Variable | Meaning |
|---|---|
| `AUTONOMY_LEVEL` | 0 scout, 1 prepare, 2 assisted apply (default), 3 guarded auto-submit |
| `AI_PROVIDER` / `AI_BASE_URL` / `AI_MODEL` | which model backend to use |
| `MAX_APPLICATIONS_PER_DAY` | hard cap on submissions |
| `DISCOVERY_CRON` / `DISCOVERY_TIMEZONE` | when discovery runs, in your timezone |
| `CHAT_DAILY_TOKEN_BUDGET` | chat degrades to read-only tools past this |

Secrets are read from the environment only. In production, placeholder values
for `SECRET_KEY` or `ENCRYPTION_KEY` refuse to start.

## Decision records

- [0001 Monorepo layout and event-driven architecture](docs/adr/0001-monorepo-and-architecture.md)
- [0002 OpenAI-compatible provider interface](docs/adr/0002-llm-provider-abstraction.md)
- [0003 Job source policy](docs/adr/0003-job-source-policy.md)
- [0004 Application consent and the approval gate](docs/adr/0004-application-consent.md)
- [0005 Chat agent tool tiers](docs/adr/0005-chat-tool-tiers.md)
