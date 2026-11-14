# GulfCart — Sprint Backlog

> **Status (2026-11-14):** Sprints 0–5 implemented. See the reports in [`milestones/`](milestones/).

8 weeks, 4 two-week sprints (Sprint 0–3) for the MVP, then Sprints 4–5.

Assumed team: 1 backend, 1 frontend, 1 QA, 1 product/technical lead. When implementing alone
with Codex, treat each sprint as a milestone and each task as one pull request.

> The 8-week target is a software MVP. Supplier agreements, payment provider approval,
> customs arrangements and production shipping integrations are separate launch gates.

## Priorities

| Priority | Meaning |
|---|---|
| P0 | Required for MVP |
| P1 | Important, can follow |
| P2 | Enhancement |
| P3 | Future roadmap |

## Sprint 0 — Project Foundation (weeks 1–2) → [Milestone 1](milestones/M1-foundation.md)

| ID | Task | Priority | Acceptance criteria |
|---|---|---|---|
| GC-001 | Initialize Laravel 13 | P0 | `apps/api` boots; `/api/v1/health/live` returns 200 |
| GC-002 | Initialize Nuxt storefront | P0 | `apps/storefront` builds; ar/en with RTL layout switch |
| GC-003 | Docker Compose | P0 | `docker compose up` starts nginx, api, worker, scheduler, storefront, postgres, redis with health checks |
| GC-004 | PostgreSQL setup | P0 | Migrations and seeders run from clean DB |
| GC-005 | Redis setup | P0 | Queue job dispatched and processed by worker; cache works |
| GC-006 | API versioning and envelope | P0 | `/api/v1` prefix, success/error envelope, request ID middleware |
| GC-007 | Authentication | P0 | Register / login / logout / me / password reset; tests |
| GC-008 | RBAC + staff MFA | P0 | Roles/permissions seeded; `/admin/*` requires permission and MFA |
| GC-009 | CI pipeline | P1 | Lint, static analysis, tests for api and storefront on every PR |
| GC-010 | Logging | P1 | JSON logs with request ID; errors include context, no PII |

Deliverable: running development environment with authentication and admin access.

## Sprint 1 — Catalog & Supplier Management (weeks 3–4)

| ID | Task | Priority | Acceptance criteria |
|---|---|---|---|
| GC-011 | Categories CRUD | P0 | Admin manages a bilingual category tree |
| GC-012 | Products CRUD | P0 | Draft → review → published → unpublished lifecycle |
| GC-013 | Product variants | P0 | Sizes and colours; unique SKU |
| GC-014 | Supplier CRUD | P0 | Supplier with authorization record; cannot activate unless approved |
| GC-015 | Supplier products | P0 | External product and variant mapping |
| GC-016 | CSV product import | P0 | Async, validated, row-level error report |
| GC-017 | Image rights validation | P0 | Images without approved rights cannot be published |
| GC-018 | Product search | P1 | Search and filters (pg_trgm) |
| GC-019 | Country availability | P0 | Product eligibility per country enforced in listing |
| GC-020 | Mock supplier connector | P0 | Configurable product/stock/price/failure simulation |

Deliverable: admin can publish products mapped to approved suppliers.

## Sprint 2 — Pricing & Shopping (weeks 5–6)

| ID | Task | Priority | Acceptance criteria |
|---|---|---|---|
| GC-021 | Exchange rates | P0 | Scheduled fetch, stale-rate alert, stored history |
| GC-022 | Pricing engine | P0 | Implements `PRICING_ENGINE.md`; worked example passes as a test |
| GC-023 | Country pricing | P0 | AED and SAR prices per variant |
| GC-024 | Shipping quotes | P0 | Flat and weight-band methods per country |
| GC-025 | Tax configuration | P0 | Separate AE and SA rules, effective-dated |
| GC-026 | Product storefront | P0 | Listing and detail pages, ar/en, RTL |
| GC-027 | Shopping cart | P0 | Add/update/remove; guest cart merge |
| GC-028 | Checkout quote | P0 | Locked checkout session with expiry |
| GC-029 | Address management | P0 | UAE and Saudi address validation |
| GC-030 | Cart validation | P0 | Stock and price revalidated with clear messages |

Deliverable: customer can browse, add to cart and obtain a complete checkout quote.

## Sprint 3 — Orders, Payments & Procurement (weeks 7–8)

| ID | Task | Priority | Acceptance criteria |
|---|---|---|---|
| GC-031 | Order creation | P0 | Transactional, idempotent, snapshots stored |
| GC-032 | Supplier order splitting | P0 | One supplier order per supplier |
| GC-033 | Payment integration | P0 | Sandbox success/failure/3DS |
| GC-034 | Payment webhooks | P0 | Signature, replay protection, idempotency |
| GC-035 | Procurement engine | P0 | Task processing with row locking |
| GC-036 | Connector interface | P0 | `SupplierConnectorInterface` + contract test |
| GC-037 | Mock supplier purchase | P0 | Success, reject, timeout, price change simulated |
| GC-038 | Procurement retries | P0 | Safe retry classification + reconciliation |
| GC-039 | Procurement dashboard | P0 | Exceptions visible and actionable |
| GC-040 | Refund workflow | P0 | Full and partial refunds, never above captured |

Deliverable: an order can be paid, split by supplier, purchased through the connector
abstraction and monitored end to end.

## Post-MVP

| Sprint | Scope |
|---|---|
| Sprint 4 | Courier integration, tracking, delivery, notifications, returns |
| Sprint 5 | Production security, load testing, reporting, monitoring, launch |

## Definition of Done

- [ ] Implementation completed within the task's scope
- [ ] Unit tests added
- [ ] Feature tests added
- [ ] OpenAPI / documentation updated
- [ ] Validation and error handling implemented
- [ ] Authorization checked (policy + permission)
- [ ] Logging implemented where needed (no PII)
- [ ] Lint, static analysis and full test suite green in CI
- [ ] QA approval
- [ ] No critical or high security issues
- [ ] Code review completed
