# GulfCart — Testing Strategy

## 1. Test layers

| Type | Scope | Tooling |
|---|---|---|
| Unit | Pricing, tax, currency conversion, rounding, state machines | Pest |
| Feature | HTTP endpoints: auth, cart, checkout, orders, refunds | Pest + Laravel HTTP tests on PostgreSQL |
| Architecture | Module boundaries, no supplier cost in customer resources | Pest `arch()` |
| Integration | Supplier connectors against sandboxes or recorded fixtures | Pest + HTTP fakes |
| Contract | Each connector passes the shared `SupplierConnectorContractTest` | Pest dataset per connector |
| Payment | Success, failure, 3DS, duplicate webhooks, out-of-order webhooks | Provider sandbox + fakes |
| Procurement | Duplicate prevention, unknown outcome, reconciliation, concurrency | Pest + parallel workers |
| Shipping | Tracking ingestion and status transitions | Pest |
| Security | OWASP checks, authorization matrix, rate limits | Pest + OWASP ZAP baseline in CI |
| Load | Concurrent checkout and catalog browsing | k6 |
| Frontend | Components, composables, i18n, RTL | Vitest + Vue Test Utils |
| E2E | Full customer journey AE and SA, ar and en | Playwright |

Milestone 1 uses PHPUnit (bundled with Laravel) for every PHP layer; moving to Pest is a
follow-up. Architecture rules are enforced by `tests/Architecture/ModuleBoundariesTest.php`.

Feature tests run against **PostgreSQL**, not SQLite, because constraints, JSONB and row
locks are part of the behaviour under test.

## 2. Critical scenarios (must have automated tests)

1. Customer buys from one supplier.
2. Customer buys from two suppliers → one order, two supplier orders, two shipments.
3. Supplier changes price after the item is in the cart → `/cart/validate` reports it.
4. Product goes out of stock before payment → checkout blocked with `PRODUCT_OUT_OF_STOCK`.
5. Payment succeeds and the webhook arrives twice → one capture, one `OrderPaid` event.
6. Payment succeeds but supplier purchase fails → exception, alternate source or refund.
7. Supplier accepts the order but tracking is late → reminder and SLA alert, no state jump.
8. Shipment arrives in parts → order `partially_shipped` then `shipped`.
9. Customer returns one item from a multi-item order → partial refund only for that item.
10. Partial refund → refund totals never exceed captured amount, even under concurrency.
11. Supplier connection fails during order submission → outcome `unknown`, reconciliation, no duplicate.
12. Exchange rate changes between quote and payment → order uses the locked quote rate.
13. Same procurement message delivered twice → exactly one purchase.
14. Two workers pick the same task simultaneously → exactly one submits.
15. Quote expired at order creation → `410 QUOTE_EXPIRED`.
16. Customer API responses never contain supplier, cost or margin fields.
17. Out-of-order supplier event (`shipped` before `purchased`) → deferred and replayed.

## 3. Coverage targets

- Pricing, Tax, Procurement, Payments domain code: ≥ 90% line coverage.
- Overall backend: ≥ 80%.
- Mutation testing (Infection) on the pricing engine before launch.

## 4. MVP release acceptance

- [ ] UAE storefront operational
- [ ] Saudi storefront operational
- [ ] Arabic and English supported, RTL verified
- [ ] AED and SAR pricing operational
- [ ] Supplier mapping operational
- [ ] At least one approved supplier connector available (or manual procurement accepted for launch)
- [ ] Payment gateway approved for both markets
- [ ] Procurement idempotency verified (scenarios 5, 11, 13, 14)
- [ ] Multiple supplier orders supported
- [ ] Shipment tracking functional
- [ ] Refund workflow tested
- [ ] Legal and customs arrangements approved
- [ ] Backup and recovery tested
