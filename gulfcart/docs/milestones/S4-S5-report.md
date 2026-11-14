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
| Approved supplier connector available | ⏳ CJdropshipping connector built ([setup](../suppliers/CJ_DROPSHIPPING.md)); needs a CJ account, API key and one live test order |
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

## Follow-up round (after Sprint 5)

Backend 196 tests (1,879 assertions) passing; storefront lint/typecheck clean, 15 tests, build OK;
browser journey re-verified (phone OTP, invoice link and print view, forgot password).

| Item | Result |
|---|---|
| Phone verification | `/auth/otp/send`, `/auth/verify-otp`: hashed 6-digit codes, 10-minute expiry, 5 attempts, single use, rate limits; `SmsSender` contract with a log driver (refused in production) until an SMS provider exists |
| Email verification | Verification email on registration; signed link (relative signature, 24 h) through the storefront; resend endpoint |
| Storefront account | Forgot/reset password pages, email verification page and banner, phone verification form |
| Tax invoices | Invoice on payment, credit note on each refund; gapless numbering per country/type/year; immutable (trigger); bilingual printable HTML; ZATCA Phase 1 QR payload for KSA |
| Live FX | `HttpRateProvider` (`FX_PROVIDER=http`) with a 10% outlier guard; refreshes in the same second no longer collide |
| Outbox | `outbox:work` long-running dispatcher (+ `outbox` service in Compose) |
| DB roles | `infrastructure/postgres/roles.sql` and `post-migrate.sql` (owner / app / reporting; append-only audit and invoices) |
| Admin UI | Returns, suppliers (activate/deactivate) and CSV import screens |

### Bugs found in this round

1. **OTP brute force:** the failed-attempt counter was incremented inside a transaction that then threw,
   so the rollback erased it. Verification now returns a result and throws after commit (regression test).
2. **Missing SMS binding:** an earlier formatter run had rewritten the provider, so a scripted edit did not
   apply; tests passed because they bind their own sender. Found by the browser run; a container test now covers it.
3. **Invoice print view unstyled:** the API security middleware replaced the page's CSP. It now keeps a
   controller-specific policy.
4. **Same-second FX refreshes** violated the unique key → insert-or-ignore.

### Still open (cannot be done without decisions or access)

| Item | Blocked by |
|---|---|
| Real supplier connector(s) | CJdropshipping ready: create account + API key; other suppliers need agreement and API access |
| Real payment gateway adapter | Payment provider choice (D2) |
| Real SMS/WhatsApp adapter | Provider choice |
| ZATCA Phase 2 e-invoicing | Legal entity + ZATCA onboarding |
| Courier adapter | Decision D3 (only if GulfCart books its own last-mile) |
| `docker compose up` verification | This environment blocks package mirrors (Alpine 403, Debian not routed); run once locally |
| Pest + Larastan | GitHub-hosted package downloads blocked here |
| Customer consent for supplier price increases | Product decision (today: GulfCart absorbs ≤ 5%, otherwise exception queue) |
