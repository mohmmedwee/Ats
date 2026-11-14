# GulfCart — Engineering Instructions for AI and Human Implementers

You are the senior software architect and lead engineer implementing GulfCart.
Read this file before every task. When this file and a document in `docs/` disagree,
stop and report the conflict instead of guessing.

## Product

GulfCart is an independent cross-border e-commerce reseller platform serving the UAE and
Saudi Arabia. It purchases products from **commercially authorized** suppliers and ships
them directly to customers. GulfCart is the seller to the customer, not an affiliate.

## Technology

- Backend: Laravel 13, PHP 8.4+
- Frontend: Nuxt (Vue 3, TypeScript, Tailwind CSS); version per `docs/architecture/decisions/ADR-003-nuxt-storefront.md`
- Database: PostgreSQL 17
- Cache and queues: Redis 7
- API: REST / JSON under `/api/v1`, documented in OpenAPI 3.1
- Authentication: Laravel Sanctum (admins also require MFA)
- Authorization: RBAC via `spatie/laravel-permission`
- Infrastructure: Docker Compose, Nginx
- Storage: S3-compatible object storage

## Architecture

Modular monolith: one Laravel application, modules under `apps/api/app/Modules/<Module>`.

Modules: Identity, Catalog, Suppliers, Pricing, Cart, Checkout, Orders, Procurement,
Payments, Shipping, Tax, Returns, Notifications, Analytics, Audit, Countries.

Module boundary rules:

1. A module exposes a public API through `Contracts/` (interfaces) and `Events/`.
2. A module never reads or writes another module's tables directly; it calls that
   module's contract or reacts to its events.
3. No circular dependencies between modules. The allowed direction is in
   `docs/TECHNICAL_SPEC.md` section 3.
4. Shared, module-independent code (Money, value objects, idempotency, outbox,
   API responses) lives in `app/Shared`.

## Non-negotiable engineering rules

1. Never assume a supplier API exists. Never scrape or automate purchases on any
   platform without written authorization. All supplier access goes through
   `SupplierConnectorInterface`. Until a connector is authorized, use `MockSupplierConnector`
   or the manual procurement fallback.
2. No store or supplier names in order, pricing or procurement logic.
3. Use idempotency keys for every payment, refund and supplier purchase, enforced by a
   unique constraint in the database, not only by queue uniqueness.
4. Use PostgreSQL transactions for order creation, splitting, payment and refund state.
5. Publish domain events through the transactional outbox (`outbox_events`).
6. Store immutable order and financial snapshots; never recompute historical totals.
7. Money is `NUMERIC` in the database and a `Money` value object (integer minor units or
   `brick/money`) in PHP. Never use `float` for money.
8. Never expose supplier identity, supplier cost or margin through customer-facing APIs.
9. No country-specific `if ($country === 'SA')` branches in business code. Country
   behaviour comes from the Countries, Tax and Shipping configuration.
10. State changes go through the state machines in `docs/PROCUREMENT_WORKFLOW.md`.
    Illegal transitions throw and are logged.
11. Follow OWASP ASVS level 2 for the API. Validate all input with Form Requests.
12. Never commit secrets, credentials or real `.env` files.
13. Document every endpoint in `docs/api/` (OpenAPI 3.1) in the same change.
14. Schema changes only through Laravel migrations; reference data through seeders.
15. TypeScript in strict mode on the frontend. All UI text through i18n (ar, en) with RTL support.

## Development process

For each milestone:

1. Inspect the repository and the relevant documents in `docs/`.
2. Write a short implementation plan and list dependencies.
3. Implement only the milestone's scope.
4. Add unit and feature tests. Run the full test suite, lint and static analysis.
5. Fix failures. Do not skip or disable tests.
6. Update the documentation and OpenAPI files.
7. Report: completed tasks, changed files, tests executed with results, remaining issues.

Do not implement procurement, payments or real supplier integrations until their
milestone. Deliver working code, not architecture descriptions.

## Quality gates

- Backend: `php artisan test` (PHPUnit, on PostgreSQL), Pint (`--test`), `composer audit`.
  Pest and Larastan level 8 are planned (see `docs/milestones/M1-report.md`, follow-ups).
- Frontend: `nuxi typecheck`, ESLint, Vitest.
- CI must be green before a task counts as done (see `docs/SPRINT_BACKLOG.md`, Definition of Done).
