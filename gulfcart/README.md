# GulfCart

Cross-border, multi-source e-commerce **reseller** platform for the UAE and Saudi Arabia.
GulfCart is the seller of record to the customer: it buys from commercially authorized
suppliers and ships directly to the customer.

> **Status:** specification baseline v1.0. No application code yet.
> The next step is **Milestone 1 — Foundation** ([`docs/milestones/M1-foundation.md`](docs/milestones/M1-foundation.md)).

## Baseline decisions

| Area | Decision |
|---|---|
| Launch markets | UAE (AE) + Saudi Arabia (SA), launched together |
| Business identity | Independent brand, GulfCart is the seller |
| Fulfillment | Automated purchasing through authorized Supplier Connectors, manual fallback |
| Company jurisdiction | **Deferred**, see [`docs/SPEC_REVIEW.md`](docs/SPEC_REVIEW.md#open-business-decisions) |
| Backend | Laravel 13, PHP 8.4+ |
| Frontend | Nuxt (Vue 3, TypeScript, Tailwind CSS), see ADR-003 for the version |
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
