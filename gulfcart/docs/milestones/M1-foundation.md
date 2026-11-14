# Milestone 1 — Foundation

Covers Sprint 0 tasks GC-001 to GC-010. Read [`../../AGENTS.md`](../../AGENTS.md) first.

## Scope

Implement **only**:

- Repository layout (`apps/api`, `apps/storefront`, `infrastructure/`, root `docker-compose.yml`, `Makefile`)
- Laravel 13 application with the module skeleton and `app/Shared`
- Nuxt storefront (version per ADR-003) with TypeScript strict, Tailwind, i18n (ar/en) and RTL
- Docker Compose: nginx, api, worker, scheduler, storefront, postgres, redis, mailpit, with health checks
- PostgreSQL migrations: countries, users, permission tables, personal access tokens,
  audit_logs, idempotency_keys, outbox_events, jobs/failed_jobs/cache
- Seeders: AE and SA countries, roles and permissions from `SECURITY_SPEC.md` §2, one super admin from env vars (dev only)
- Authentication: register, login, logout, me, forgot/reset password
- Staff MFA (TOTP) and `/admin` route group requiring permission + MFA
- API envelope, error handler mapping to error codes, request ID middleware, `/api/v1` prefix
- Health endpoints `/api/v1/health/live` and `/api/v1/health/ready`
- JSON logging with request ID
- CI (GitHub Actions): Pint, Larastan, Pest on PostgreSQL + Redis services; ESLint, typecheck, Vitest, Nuxt build
- OpenAPI updated for every endpoint added

Out of scope: catalog, suppliers, pricing, cart, checkout, orders, payments, procurement.

## Backend skeleton

```text
apps/api/app/
├── Shared/
│   ├── Http/ApiResponse.php           # success/error envelope
│   ├── Http/Middleware/RequestId.php
│   ├── Http/Middleware/SetLocaleAndCountry.php
│   ├── Exceptions/ApiExceptionHandler.php
│   ├── Money/Money.php                # brick/money wrapper (tests only in M1)
│   ├── Idempotency/                   # middleware + model (used from Sprint 3)
│   └── Outbox/                        # model + dispatcher command
├── Modules/
│   ├── Identity/   # auth, MFA, roles
│   ├── Countries/  # model, seeder, CountryContext
│   └── Audit/      # AuditLogger contract + listener
└── Providers/ModuleServiceProvider.php  # registers each module's provider
```

Other modules get an empty directory with a `README.md` stating their future scope.

## Acceptance criteria

1. `make up` (or `docker compose up -d`) on a clean machine brings every service to healthy.
2. `make migrate-fresh` runs all migrations and seeders without errors.
3. `GET /api/v1/health/ready` returns `200` with `postgres`, `redis` and `queue` all `ok`,
   and `503` when Redis is stopped.
4. A customer can register, log in, call `/auth/me`, and log out from the storefront.
5. A staff user without MFA is redirected to MFA enrolment and cannot call `/api/v1/admin/*`.
6. A user without `users.manage` receives `403 FORBIDDEN` on `/api/v1/admin/users`.
7. Every response includes `X-Request-Id` and `meta.request_id`; logs contain the same ID.
8. Validation errors return `422 VALIDATION_FAILED` in the error envelope, localized (ar/en).
9. Storefront renders RTL in Arabic and LTR in English, with a language switcher.
10. A dispatched test job is processed by the worker container.
11. An architecture test fails if one module imports another module's `Infrastructure` namespace.
12. CI is green; no secrets are committed (`.env.example` only).

## Required tests (minimum)

- Registration: email, phone, duplicate, weak password
- Login: success, wrong password, rate limit
- Logout invalidates session/token
- Password reset flow
- MFA: enrolment, challenge, admin route blocked without MFA
- RBAC: permission allowed/denied matrix for one admin endpoint
- Health: ready OK and Redis-down cases
- Request ID propagation; error envelope shape
- Country seeder produces AE/AED and SA/SAR
- Storefront: i18n switch and RTL direction (Vitest)

## Report at the end

Completed tasks (by GC-ID), changed files, commands and test results, remaining issues,
and any deviation from these documents with the reason.
