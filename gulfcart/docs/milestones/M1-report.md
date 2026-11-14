# Milestone 1 — Completion Report

Date: 2026-11-14 · Scope: [`M1-foundation.md`](M1-foundation.md) (GC-001 … GC-010)

## Completed tasks

| ID | Task | Result |
|---|---|---|
| GC-001 | Laravel 13 | `apps/api`, Laravel 13.42, modular layout (`app/Modules`, `app/Shared`) |
| GC-002 | Nuxt storefront | `apps/storefront`, Nuxt 4.6, TypeScript strict, Tailwind, `@nuxtjs/i18n` (ar RTL / en), Pinia |
| GC-003 | Docker Compose | nginx, api, worker, scheduler, storefront, postgres 17, redis 7, mailpit, with health checks |
| GC-004 | PostgreSQL | Migrations + seeders (countries, roles/permissions, dev super admin) |
| GC-005 | Redis | Cache, sessions, queues; `app:queue-probe` round-trip command |
| GC-006 | API versioning and envelope | `/api/v1`, success/error envelope, error-code mapping, request ID, locale and country middleware |
| GC-007 | Authentication | register, login (email or phone), logout, me, forgot/reset password; SPA session + token clients |
| GC-008 | RBAC + staff MFA | 14 permissions, 7 roles; TOTP with replay protection and recovery codes; `/admin/*` requires permission + MFA |
| GC-009 | CI | `.github/workflows/gulfcart-ci.yml`: Pint, composer audit, PHPUnit on PG+Redis; ESLint, typecheck, Vitest, build; OpenAPI lint |
| GC-010 | Logging | JSON logs to stderr with `request_id` context; no request bodies logged |

Also delivered from the foundation scope: `idempotency_keys` + `EnsureIdempotency` middleware,
transactional outbox (`outbox_events`, `Outbox::record`, `outbox:dispatch`), append-only audit log
with secret redaction, `Money` value object, module boundary test, OpenAPI coverage test.

## Acceptance criteria

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | `docker compose up` brings every service to healthy | **Not verified here** | Compose file validates; image builds were blocked in the build sandbox (no DNS in build containers, Docker Hub 429). Needs one run on a developer machine or CI. |
| 2 | `migrate:fresh --seed` from clean DB | ✅ | Ran on PostgreSQL 16 locally (CI uses 17) |
| 3 | `/health/ready` 200, and 503 when Redis is down | ✅ | `HealthTest` + live curl |
| 4 | Register, login, me, logout from the storefront | ✅ | Playwright journey against `artisan serve` + `nuxi dev` |
| 5 | Staff without MFA cannot use `/admin/*` and is sent to enrolment | ✅ | `MfaTest`; browser redirected to `/ar/mfa?setup=1` |
| 6 | Missing permission → `403 FORBIDDEN` | ✅ | `RbacTest` |
| 7 | `X-Request-Id` in header, meta and logs | ✅ | `RequestIdAndErrorsTest` |
| 8 | Localized `422 VALIDATION_FAILED` | ✅ | `RegistrationTest::test_validation_messages_are_localized_in_arabic` |
| 9 | RTL Arabic / LTR English with switcher | ✅ | Playwright: `dir=rtl`, `lang=ar` on `/ar/account`; Vitest |
| 10 | Dispatched job processed by a worker | ✅ (native) | `app:queue-probe` against `queue:work redis` |
| 11 | Architecture test for module boundaries | ✅ | `ModuleBoundariesTest` |
| 12 | CI green, no secrets committed | ⏳ | Workflow added; first CI run happens on push/PR. Only `.env.example` committed |

## Test results

- Backend: **76 tests, 539 assertions, all passing** (`php artisan test`, PostgreSQL + Redis).
- Pint: passing.
- Storefront: ESLint clean, `nuxt typecheck` 0 errors, Vitest 12/12, production build succeeds.
- OpenAPI: `redocly lint` valid (27 style warnings, e.g. missing `operationId`).
- Browser journey (Playwright): customer register → account → logout → login → switch to Arabic
  (RTL) → Arabic error on wrong password → staff login redirected to MFA enrolment.

## Bug found during end-to-end testing

Country lookups cached `CountryData` objects. With Redis, Laravel 13's
`cache.serializable_classes = false` turned them into `__PHP_Incomplete_Class`, so every
request failed with 500. Feature tests missed it because the array cache did not serialize.
Fixed by caching plain arrays; tests now run with a serializing array cache
(`CACHE_ARRAY_SERIALIZE=true`), and a regression test was confirmed to fail on the old code.

## Deviations from the documents

1. **PHPUnit instead of Pest, and no Larastan yet.** Their packages are downloaded from GitHub,
   which was blocked in the build environment (HTTP 403). Tests are plain PHPUnit; the boundary
   test is a PHPUnit file scan instead of Pest `arch()`. Follow-up below.
2. **Health responses use the standard envelope** (`success`, `data.status`, `data.checks`), with
   `success: false` on 503. OpenAPI updated.
3. **Phone OTP endpoints** (`/auth/otp/send`, `/auth/verify-otp`) moved to Sprint 2, when the SMS
   provider is chosen. Phone numbers are accepted and unique, but not yet verified.
4. **Auth checks in the storefront run client-side** (`import.meta.server` guard), because the
   session cookie is not forwarded during SSR. Account and admin pages render a shell on the server.

## Remaining issues and follow-ups

| Item | Priority |
|---|---|
| Run `make up` once on a machine with normal Docker Hub access; confirm criterion 1 | P0 |
| Add Pest + `pest-plugin-arch` and Larastan level 8 to CI, convert tests | P1 |
| Storefront MFA enrolment UI (QR code + confirm); the API is done, the page currently only explains the requirement | P1 |
| Reset-password page in the storefront (API done) | P1 |
| Email verification flow | P1 |
| Move GulfCart into its own repository; move the CI workflow to `gulfcart/.github/workflows/` | P1 |
| Separate DB roles (DML vs DDL; `audit_logs` INSERT/SELECT only) in staging/production | P1 |
| Outbox dispatcher runs every minute via the scheduler; switch to a long-running loop (1 s) before Sprint 3 | P2 |
