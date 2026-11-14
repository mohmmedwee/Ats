# GulfCart

Cross-border, multi-source e-commerce **reseller** platform for the UAE and Saudi Arabia.
GulfCart is the seller of record to the customer: it buys from commercially authorized
suppliers and ships directly to the customer.

> **Status:** MVP software complete (Sprints 0–5 + follow-up round) plus a CJdropshipping connector and a full admin panel. 212 backend tests, storefront in Arabic and English,
> full purchase journey verified in a browser. Commercial launch still needs the business decisions in
> [`docs/milestones/S4-S5-report.md`](docs/milestones/S4-S5-report.md#what-remains-before-commercial-launch)
> (suppliers, payment provider, jurisdiction, customs).
>
> Reports: [M1](docs/milestones/M1-report.md) · [S1](docs/milestones/S1-report.md) · [S2](docs/milestones/S2-report.md) · [S3](docs/milestones/S3-report.md) · [S4–S5](docs/milestones/S4-S5-report.md)

## Quick start

```bash
cd gulfcart
make up                 # docker compose up -d --build
make migrate-fresh      # migrations + seeders (AE, SA, roles, tax, shipping, FX, dev super admin)
make demo               # demo catalog with an authorized MOCK supplier
open http://localhost   # storefront; API at http://localhost/api/v1
```

Dev super admin (local only, from `apps/api/.env`): `admin@gulfcart.local` / `ChangeMe-123`.

Admin panel (`/admin`, Arabic and English): dashboard with sales and profit, orders with cost and margin,
procurement exceptions, refunds (four-eyes), returns, products (edit, translate, publish), CJ catalog search and
import, CSV imports, categories, suppliers and agreements, pricing rules and FX, shipping, VAT and customs,
country settings, team invites and roles, audit log.
Staff must enrol in TOTP MFA before using `/admin`. Mail is captured by Mailpit at http://localhost:8025.

Without Docker: run PostgreSQL and Redis locally, then `php artisan serve` in `apps/api`
and `npm run dev` in `apps/storefront` (see each app's README).

## Baseline decisions

| Area | Decision |
|---|---|
| Launch markets | UAE (AE) + Saudi Arabia (SA), launched together |
| Business identity | Independent brand, GulfCart is the seller |
| Fulfillment | Automated purchasing through authorized Supplier Connectors, manual fallback |
| Company jurisdiction | **Deferred**, see [`docs/SPEC_REVIEW.md`](docs/SPEC_REVIEW.md#open-business-decisions) |
| Backend | Laravel 13, PHP 8.4+ |
| Frontend | Nuxt 4 (Vue 3, TypeScript, Tailwind CSS) |
| Data | PostgreSQL 17, Redis 7 |
| Architecture | Modular monolith, REST/JSON `/api/v1`, OpenAPI 3.1 |
| Languages / currencies | Arabic + English, AED + SAR |
| Infrastructure | Docker Compose + Nginx, S3-compatible storage |

## Documentation map

| Document | What it covers |
|---|---|
| [`AGENTS.md`](AGENTS.md) | Engineering rules for Codex and other AI or human implementers |
| [`docs/PRD.md`](docs/PRD.md) | Product requirements, scope and launch gates |
| [`docs/TECHNICAL_SPEC.md`](docs/TECHNICAL_SPEC.md) | Architecture, modules, structure, cross-cutting rules |
| [`docs/DATABASE_SCHEMA.md`](docs/DATABASE_SCHEMA.md) | PostgreSQL schema, indexes, financial integrity rules |
| [`docs/API_SPECIFICATION.md`](docs/API_SPECIFICATION.md) | REST conventions and endpoint catalogue |
| [`docs/api/openapi.yaml`](docs/api/openapi.yaml) | OpenAPI 3.1 root document |
| [`docs/PROCUREMENT_WORKFLOW.md`](docs/PROCUREMENT_WORKFLOW.md) | Order splitting, procurement, connectors, state machines |
| [`docs/PRICING_ENGINE.md`](docs/PRICING_ENGINE.md) | Landed cost, margin, VAT and rounding |
| [`docs/COUNTRY_CONFIGURATION.md`](docs/COUNTRY_CONFIGURATION.md) | Per-country tax, shipping, customs, locale |
| [`docs/SECURITY_SPEC.md`](docs/SECURITY_SPEC.md) | Security and reliability requirements |
| [`docs/TESTING_STRATEGY.md`](docs/TESTING_STRATEGY.md) | Test layers, critical scenarios, release acceptance |
| [`docs/SPRINT_BACKLOG.md`](docs/SPRINT_BACKLOG.md) | Sprints, tasks, Definition of Done |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Docker services, environments, production hardening |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Scheduled jobs, daily checks, procurement exception playbook, incidents, backups |
| [`docs/SUPPLIER_IMPORT.md`](docs/SUPPLIER_IMPORT.md) | Supplier CSV feed format |
| [`docs/suppliers/CJ_DROPSHIPPING.md`](docs/suppliers/CJ_DROPSHIPPING.md) | Ready-made CJdropshipping supplier: setup, import, verification |
| [`docs/SPEC_REVIEW.md`](docs/SPEC_REVIEW.md) | Corrections made to the v1.0 draft and open decisions |
| [`docs/architecture/`](docs/architecture/) | System context, containers, ADRs |

## Target repository layout

```text
gulfcart/
├── AGENTS.md
├── apps/
│   ├── api/            # Laravel 13 modular monolith
│   └── storefront/     # Nuxt storefront + admin
├── infrastructure/
│   ├── docker/
│   ├── nginx/
│   └── monitoring/
├── docs/
└── docker-compose.yml
```

## Delivery phases

1. **Foundation**: repository, Docker, Laravel, Nuxt, PostgreSQL, Redis, auth, RBAC, CI
2. **Commerce core**: catalog, suppliers, pricing, cart, checkout
3. **Transaction engine**: orders, payments, procurement, connectors, refunds
4. **Fulfillment**: shipping, tracking, notifications, returns
5. **Production**: security, load testing, monitoring, deployment

Work one milestone at a time. Never implement all modules in one step.
