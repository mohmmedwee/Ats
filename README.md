# Job Agent

A self-hosted agent that discovers relevant roles, scores them against your CV,
prepares tailored application materials, fills supported application forms, and
tracks every application — pausing for your approval before anything reaches an
employer.

The full specification is [`job-agent-plan.md`](job-agent-plan.md). Development
follows its phases in order.

## Status

**Phases 0 through 3 are complete.** What runs today:

| Piece | State |
|---|---|
| Monorepo, tooling, CI | done |
| Database schema and migrations | done, spine tables from plan section 8 |
| API skeleton, health/readiness, policy endpoint | done |
| Chat tool registry and tier enforcement | done, with handlers arriving in Phase 8 |
| AI provider interface | done, Ollama / MLX / OpenAI-compatible / fake |
| Worker, broker, timezone-aware scheduler | done, actors arrive with their phases |
| CV upload, text extraction, profile parsing | done |
| Fact provenance, answer bank, profile editor | done |
| Greenhouse, Lever, and Ashby connectors | done |
| Normalization, deduplication, raw snapshots | done |
| Scheduled discovery, sources and jobs UI | done |
| Hard filters, weighted scoring, evidence, review queue | done |
| Application packs, assisted applying | not started (Phases 4-7) |

## What it will not do

These are design constraints, not defaults:

- It never submits an application without your explicit approval of the exact
  materials.
- The chat agent has no tool that can submit, start a form, or change a policy.
  Attempting to register one raises; the database rejects the row as well.
- It never invents an employer, date, skill, metric, or certification that is
  not in your verified facts. Every value a model extracts from your CV is
  checked back against the document; anything it cannot find is discarded and
  shown to you rather than stored.
- Nothing a model produces is ever marked as confirmed by you. That provenance
  has one source: you, in the UI.
- No model sets a match score. Scoring is a pure function of your verified facts
  and the posting text, every matched and missing requirement carries a
  reference you can check, and unchanged inputs reproduce the same number.
- It does not bypass CAPTCHA or OTP, and it does not scrape authenticated
  LinkedIn pages.
- It reads job boards through their documented public APIs, one source at a
  time, at a rate limit you set. A posting that tries to instruct the agent is
  flagged and shown to you; it is never followed.

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
packages/cv               CV text extraction, grounded parsing, merge rules
packages/chat             tool registry, tiers, prompt zones, injection scanner
packages/connectors       ATS adapters, normalization, deduplication
packages/discovery        discovery orchestration shared by the API and worker
packages/matching         preferences, hard filters, scoring, explanation
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
| `STORAGE_DIR` | where uploaded CVs are written, encrypted |
| `MAX_RESUME_BYTES` | upload size cap, 10 MB by default |

Secrets are read from the environment only. In production, placeholder values
for `SECRET_KEY` or `ENCRYPTION_KEY` refuse to start.

Uploaded CVs and the text extracted from them are encrypted at rest with
`ENCRYPTION_KEY`. Rotating that key makes existing uploads unreadable.

## Adding job sources

Add a board on the Sources page, or:

```bash
curl -X POST http://localhost:8000/api/v1/sources \
  -H 'Idempotency-Key: 1' -H 'Content-Type: application/json' \
  -d '{"kind":"greenhouse","name":"acme","config":{"board_token":"acme"}}'
```

`GET /api/v1/sources/kinds` lists what each kind needs. Discovery runs on the
schedule in `DISCOVERY_CRON`, or on demand with `POST /api/v1/discovery/run`.
Contract tests replay recorded fixtures, so CI never calls a real board.

## Scoring and the review queue

Once jobs are discovered, score them against your profile:

```bash
curl -X POST http://localhost:8000/api/v1/matches/run \
  -H 'Idempotency-Key: 1' -H 'Content-Type: application/json' -d '{"explain": false}'
```

`GET /api/v1/matches` is the review queue; `GET /api/v1/jobs/{id}/match` returns
the full breakdown with evidence. Your preferences — target countries,
commutable cities, compensation floor, sponsorship, excluded companies and
titles — live on the profile and drive the hard filters.

Set `"explain": true` to have the model write the summary as well. It never sets
the number, and any point it makes that does not cite real evidence is dropped.

## Working with your own CV

`fixtures/resumes/sample_engineering_lead.docx` is a synthetic CV used by the
tests; a real CV is personal data and is not committed. To work with yours,
upload it through the profile page, or drop it in `fixtures/resumes/` locally —
that directory is gitignored apart from the sample.

## Decision records

- [0001 Monorepo layout and event-driven architecture](docs/adr/0001-monorepo-and-architecture.md)
- [0002 OpenAI-compatible provider interface](docs/adr/0002-llm-provider-abstraction.md)
- [0003 Job source policy](docs/adr/0003-job-source-policy.md)
- [0004 Application consent and the approval gate](docs/adr/0004-application-consent.md)
- [0005 Chat agent tool tiers](docs/adr/0005-chat-tool-tiers.md)
- [0006 Every extracted claim is checked against the CV](docs/adr/0006-cv-grounding.md)
- [0007 Deduplication order and per-source failure isolation](docs/adr/0007-discovery-dedup-and-isolation.md)
- [0008 The score is deterministic; the model only explains it](docs/adr/0008-deterministic-scoring.md)
