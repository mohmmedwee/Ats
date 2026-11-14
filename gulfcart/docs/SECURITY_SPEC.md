# GulfCart — Security and Reliability Specification

The system handles money, customer addresses and external purchasing. These requirements
apply from Milestone 1.

## 1. Security requirements

| Area | Requirement |
|---|---|
| Standard | OWASP ASVS 4.0 level 2 for the API and admin |
| Authentication | Sanctum; SPA cookie mode for the storefront with CSRF protection; bearer tokens with expiry for other clients |
| Passwords | Argon2id/bcrypt via Laravel hasher; min length 10; breached-password check (`Password::uncompromised()`) |
| Staff MFA | TOTP mandatory for all staff users; recovery codes hashed; enforced by middleware on `/admin/*` |
| Authorization | RBAC via `spatie/laravel-permission`; policies check permissions; deny by default |
| Admin access | Separate admin route group, optional IP allowlist, shorter session lifetime (30 min idle) |
| Rate limiting | Login 5/min per IP+login, OTP 3 per 10 min, API 120/min per user, 60/min per IP for guests |
| Input | Form Requests for every write endpoint; reject unknown fields on financial endpoints |
| SQL injection | Eloquent / query builder bindings only; no raw string interpolation |
| XSS | Vue escaping; no `v-html` with user or supplier content unless sanitized server-side |
| CSRF | Sanctum stateful domains + `XSRF-TOKEN` |
| Headers | HSTS, CSP, `X-Content-Type-Options`, `Referrer-Policy`, `frame-ancestors 'none'` |
| CORS | Allow only the storefront and admin origins |
| Secrets | Never in Git; environment variables injected at deploy, or a secret manager; `credentials_ref` points to the store |
| Supplier credentials | Encrypted at rest (Laravel encrypted casts with a dedicated key), never logged |
| Webhooks | Signature verification, timestamp tolerance 5 min, replay protection via event ID uniqueness |
| PII | Minimise; encrypt phone and address fields at rest where practical; mask in logs |
| Card data | Never touches our servers (hosted fields / redirect); PCI scope SAQ-A |
| Audit | All staff writes, logins, permission changes, refunds and procurement actions audited, append-only |
| Dependencies | `composer audit` and `npm audit` in CI; Dependabot or Renovate |
| Uploads | CSV and images: size limits, MIME sniffing, stored privately, scanned before processing |

## 2. Roles and permissions (initial)

| Permission | customer | support | operations | catalog | finance | admin | super_admin |
|---|---|---|---|---|---|---|---|
| `orders.view_own` | ✓ | | | | | | ✓ |
| `orders.view_any` | | ✓ | ✓ | | ✓ | ✓ | ✓ |
| `catalog.manage` | | | | ✓ | | ✓ | ✓ |
| `catalog.publish` | | | | ✓ | | ✓ | ✓ |
| `suppliers.manage` | | | | | | ✓ | ✓ |
| `procurement.view` | | | ✓ | | ✓ | ✓ | ✓ |
| `procurement.act` | | | ✓ | | | ✓ | ✓ |
| `pricing.manage` | | | | | ✓ | ✓ | ✓ |
| `refunds.request` | | ✓ | ✓ | | | ✓ | ✓ |
| `refunds.approve` | | | | | ✓ | ✓ | ✓ |
| `countries.manage` | | | | | | ✓ | ✓ |
| `users.manage` | | | | | | ✓ | ✓ |
| `roles.manage` | | | | | | | ✓ |
| `audit.view` | | | | | ✓ | ✓ | ✓ |

Refunds above a configurable threshold require a second approver (four-eyes).

## 3. Reliability requirements

- PostgreSQL transactions around every multi-row financial state change.
- Redis queues with named queues: `procurement`, `payments`, `outbox`, `notifications`, `default`.
- Retry policies per job (`$tries`, `backoff()`); jobs that touch suppliers or payments are
  idempotent and never retried blindly after an unknown outcome.
- Dead-letter handling: `failed_jobs` monitored; procurement failures become exceptions in the admin queue.
- Idempotency keys stored in the database (`idempotency_keys`) with request hash.
- Distributed locks (`Cache::lock`) only as an optimisation; correctness comes from DB constraints and row locks.
- Transactional outbox for all domain events.
- Daily supplier and payment reconciliation reports.
- Redis persistence (AOF) enabled; Redis is not a source of truth for any financial state.

## 4. Idempotent request handling (algorithm)

1. Require `Idempotency-Key` on the endpoint; compute SHA-256 of the canonical body.
2. `INSERT … ON CONFLICT DO NOTHING` into `idempotency_keys` with `locked_until = now()+60s`.
3. If the row exists with a stored response and matching hash, return that response.
4. If it exists with a different hash, return `409 IDEMPOTENCY_KEY_REUSED`.
5. If it exists and is locked without a response, return `409 CONFLICT` (request in progress).
6. Otherwise execute, store status and body, release the lock. Keys expire after 24 h.

## 5. Incident basics

- Error tracking (Sentry or equivalent) with PII scrubbing.
- Alerts: procurement exceptions > N/hour, webhook failures, stale FX rates, failed jobs,
  queue latency, 5xx rate.
- Backups: PostgreSQL daily full + WAL archiving (PITR), restore tested monthly.
