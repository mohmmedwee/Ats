# Sprints 4–5 — Fulfillment, Production Readiness & Storefront: Report

Backend: **182 tests, 1,707 assertions, all passing**. Storefront: lint clean, typecheck 0 errors,
15 unit tests, production build OK. Full browser journey verified (see below).

## Sprint 4 — fulfillment

| Item | Result |
|---|---|
| Tracking | Supplier tracking → shipments + events; customer tracking per package; order status derived forward-only |
| Delivery | Supplier `delivered` → items and order delivered |
| Notifications | Bilingual emails (Arabic RTL) on paid, shipped, delivered, cancelled, refund, return decisions; ops alerts on procurement exceptions; deduplicated; SMS/WhatsApp logged until a provider exists |
| Returns | Delivered items only, per-country window (AE 14 / SA 7 days, configurable), quantity reservation, approve → receive → refund (unit price × quantity) |
| Courier integration | **Not built**: shipments come from supplier tracking. A carrier adapter is needed only if GulfCart books its own last-mile (decision D3) |

## Sprint 5 — production readiness

| Item | Result |
|---|---|
| Security | API security headers; CORS limited to storefront origins; address PII encrypted at rest; mock supplier and sandbox gateway refused in production; webhook signature + replay protection |
| Reporting | Admin dashboard KPIs; finance report (gross, VAT, customs, refunds, landed cost, fees, contribution) per country/currency; audit-log browser |
| Admin config | Country settings (rounding, return window, payment methods, seller VAT number, importer of record) |
| Load tests | k6 scripts for catalog and checkout (`infrastructure/load`) — to run on staging |
| Backups | Backup + restore-drill scripts (`infrastructure/scripts`) |
| Runbook | [`OPERATIONS.md`](../OPERATIONS.md) |
| Smoke test | `apps/storefront/e2e/smoke-journey.cjs` |

## Storefront (built in this phase)

Products (search, categories, sort, cursor paging), product detail (variants, stock, delivery),
cart (guest token, merge on sign-in, change notices), checkout (country address forms, shipping
options, multi-package quote with customs and VAT), sandbox payment page, orders (packages,
tracking, cancel, pay again, returns), staff MFA enrolment with recovery codes, admin dashboard,
procurement queue actions, refunds approval, product publishing.

### Verified browser journey (Playwright, real API + Redis worker)

Arabic RTL catalog → add to cart as guest → register (cart merged) → address with spaced phone
number → quote 199.00 AED (184 + 15 delivery, VAT 9.47 included) → place order → sandbox payment →
worker buys from the mock supplier → order "Being prepared" → super admin enrols TOTP (8 recovery
codes) → dashboard shows paid revenue.

### Bugs found by the journey

1. `pages/account.vue` became a parent route for `/account/orders/*`, so order pages rendered
   nothing → moved to `account/index.vue`.
2. Notification dedupe caught a unique violation, which aborts the PostgreSQL transaction and broke
   the outbox dispatcher → switched to `INSERT … ON CONFLICT DO NOTHING` (covered by a redelivery test).

## MVP release acceptance (`TESTING_STRATEGY.md` §4)

| Criterion | Status |
|---|---|
| UAE storefront operational | ✅ software |
| Saudi storefront operational | ✅ software |
| Arabic and English supported | ✅ |
| AED and SAR pricing operational | ✅ |
| Supplier mapping operational | ✅ |
| Approved supplier connector available | ⏳ mock + manual connectors ready; a real connector needs a signed supplier agreement |
| Payment gateway approved for both markets | ⏳ gateway contract + sandbox ready; provider pending (D2) |
| Procurement idempotency verified | ✅ (scenarios 5, 11, 13, 14 tested) |
| Multiple supplier orders supported | ✅ |
| Shipment tracking functional | ✅ via supplier tracking |
| Refund workflow tested | ✅ |
| Legal and customs arrangements approved | ⏳ business decision |
| Backup and recovery tested | ⏳ scripts ready; drill must run on the real environment |

## What remains before commercial launch

| Item | Owner |
|---|---|
| Company jurisdiction, VAT registration, Importer of Record (AE, SA) | Business / legal |
| Supplier agreement(s) → implement `SupplierConnector` for each | Business, then 1–2 weeks engineering per supplier |
| Payment provider (AED, SAR, mada, Apple Pay) → implement `PaymentGateway` | Business, then ~1 week engineering |
| Confirm customs duty, de minimis, return windows and VAT treatment of customs with advisors; update admin settings | Tax / customs advisor |
| SMS/WhatsApp provider for OTP and notifications | Business |
| Live FX provider (`ExchangeRateProvider`) | Engineering, small |
| First `docker compose up`, staging deploy, k6 runs, restore drill | Engineering |
| Pest + Larastan level 8 (blocked here by GitHub downloads) | Engineering |
| Move GulfCart to its own repository | Owner |
