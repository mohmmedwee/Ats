# Sprint 3 — Orders, Payments & Procurement: Report

Scope: GC-031 … GC-040. Result: **implemented**. Backend: 171 tests, 1,532 assertions, all passing.

| ID | Task | Result |
|---|---|---|
| GC-031 | Order creation | One transaction from the locked checkout session; immutable item snapshots enforced by a DB trigger; one order per session; `Idempotency-Key` required |
| GC-032 | Supplier order splitting | On `order.paid` (outbox): one supplier order + task per supplier, under the order row lock |
| GC-033 | Payment integration | `PaymentGateway` contract + sandbox gateway (hosted-page model, PCI SAQ-A); intents reused, retries after failure |
| GC-034 | Payment webhooks | HMAC signature, 5-minute replay window, dedup by provider event ID, amount/currency check |
| GC-035 | Procurement engine | Claim with `SKIP LOCKED`, eligibility, revalidation of stock and cost, idempotent submission |
| GC-036 | Connector interface | `Suppliers\Contracts\SupplierConnector` (ADR-006 amendment) |
| GC-037 | Mock purchase | Accept, reject, transient, unknown-then-accept, unknown-then-missing, tracking progression |
| GC-038 | Retries | Safe-retry classification, backoff 1/5/15/60/240 min, reconciliation after unknown outcomes, stale-claim recovery |
| GC-039 | Procurement dashboard | API: queue with counts, attempt log, retry / manual approve / switch source / cancel + refund (UI in the storefront admin) |
| GC-040 | Refund workflow | Requested → approved → processing → succeeded; four-eyes above 500; never above captured (in-flight counted) |

Also delivered: shipment recording and customer tracking (part of Sprint 4 scope), order expiry,
customer cancellation before purchase with automatic refund.

## Critical scenarios covered by tests

From `TESTING_STRATEGY.md` §2: one supplier (1), two suppliers (2), price changed after cart (3,
Sprint 2), out of stock before payment (4, Sprint 2), duplicate webhook (5), payment ok but
purchase fails (6), late tracking (7, tracking idempotent), partial shipments (8, aggregator),
partial refund (10), connection failure during purchase (11), FX change (12, locked quote),
duplicate procurement message (13), concurrent workers (14, claim + status lock), expired quote
(15), no supplier data in customer APIs (16), out-of-order events (17, forward-only projection).
Scenario 9 (single-item return) arrives with the Returns module.

## Decisions

1. **Dependency inversion for cross-module flows.** Orders defines `OrderBook`, `OrderRefunder`,
   `CancellationPolicy`, `FulfillmentView`; Payments, Procurement and Shipping implement them.
   No module depends on another's internals; the boundary test enforces it.
2. **Outbox + after-commit dispatch.** `Outbox::record` queues a dispatch job after commit;
   `outbox:dispatch` every minute is the safety net. Each event is handled in a savepoint.
3. **Order status vs money.** `orders.status` is the lifecycle; `refund_state` (none/partial/full)
   tracks money. Fully refunded unfulfilled orders end as `cancelled`.
4. **Late captures are honoured.** A capture arriving after expiry pays the order at the locked price.
5. **Procurement jobs are never retried by the queue** (`tries = 1`); the engine owns retries and
   `procurement:run` recovers crashed runs, so a queue retry can never double-purchase.
6. **Unknown outcome → reconcile.** If the supplier has the order, record it; if not and the
   connector guarantees idempotency, retry with the same key; otherwise an operator must confirm
   and issue a new key version.
7. **Customer price is never raised.** Cost increases within 5% are absorbed; above that, the
   order goes to the exception queue (switch source or cancel + refund). Asking the customer to
   accept a higher price is a future enhancement.
8. **Sandbox gateway** until decision D2 (payment provider) is made; refused in production.
