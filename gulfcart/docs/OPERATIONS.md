# GulfCart — Operations Runbook

## Processes

| Process | Command | Notes |
|---|---|---|
| API | php-fpm (Docker `api`) | Stateless; scale horizontally |
| Workers | `php artisan queue:work redis --queue=procurement,payments,outbox,notifications,default --tries=1` | `tries=1`: procurement and payments own their retries |
| Scheduler | `php artisan schedule:work` | Exactly **one** instance |
| Storefront | Nuxt SSR | Scale horizontally |

## Scheduled jobs

| Job | Frequency | Purpose |
|---|---|---|
| `outbox:dispatch` | every minute | Safety net for events whose after-commit dispatch was lost |
| `procurement:run` | every minute | Due tasks (`queued`, `waiting_retry`); releases stale claims (> 10 min) |
| `procurement:reconcile` | every 15 min | Supplier orders in `submitting`/`unknown` → ask the supplier |
| `procurement:track` | every 30 min | Supplier status and tracking → shipments, order status |
| `orders:expire-unpaid` | every 5 min | Orders past `payment_due_at` → `expired` |
| `fx:refresh --reprice` | hourly | New exchange-rate snapshot, reprice everything |
| `suppliers:sync` | every 30 min | Cost and stock from connectors that support catalog sync |
| `sanctum:prune-expired` | daily | Expired API tokens |
| idempotency prune | hourly | Expired idempotency keys |

## Daily checks (admin dashboard)

1. **Procurement exceptions / manual purchases** → `/admin/procurement`.
2. **Refunds awaiting approval** → `/admin/refunds` (four-eyes above 500 AED/SAR).
3. **Exchange rates stale** banner → check the FX provider; products leave sale after 24 h.
4. **Failed outbox events** (`outbox_failed` > 0) → inspect `outbox_events.last_error`, fix, set `status = 'pending'`.

## Procurement exception playbook

| Reason | Action |
|---|---|
| `PRICE_INCREASE_ABOVE_TOLERANCE` | Check `exception_details`. Accept by recording a manual purchase, **switch source**, or **cancel & refund**. Never charge the customer more. |
| `OUT_OF_STOCK` | Switch source if another authorized supplier has it; otherwise cancel & refund. |
| `SUPPLIER_REJECTED` | Read the attempt log. Fix data (address, variant) and **retry**, or switch source. |
| `SUPPLIER_NOT_ELIGIBLE` | Supplier inactive or authorization expired. Renew authorization or switch source. |
| `RETRIES_EXHAUSTED` | Supplier API down for hours. Retry later or place manually. |
| `UNKNOWN_OUTCOME_UNRESOLVED` | **Do not retry blindly.** Ask the supplier whether order `idempotency_key` exists. If it does, record it as a manual purchase. If not, retry with `new_idempotency_key=true`. |
| `SUPPLIER_CANCELLED` (after purchase) | Switch source or cancel & refund. |
| Manual supplier | Place the order on the supplier's site/portal, then **record manual purchase** with their order number and actual cost. |

## Incidents

| Symptom | First steps |
|---|---|
| Payments captured but orders stay `pending_payment` | `payment_webhook_events` with `status='failed'` (amount mismatch → contact provider); worker running? |
| Orders paid but no supplier orders | `outbox_events` pending/failed for `order.paid`; run `php artisan outbox:dispatch`; check worker logs |
| Many `reconciling` tasks | Supplier API degraded; `procurement:reconcile` resolves them when it recovers |
| `/health/ready` 503 | Which check is down (postgres/redis/queue); Redis loss delays work but loses no financial state |

## Backups

- Managed PostgreSQL with PITR (WAL) in production, plus `infrastructure/scripts/backup-postgres.sh` daily (encrypted, off-site).
- Monthly `infrastructure/scripts/restore-drill.sh`; record the result.
- Redis is not backed up for correctness (AOF on for convenience); every job can be rebuilt from PostgreSQL.

## Load and smoke tests

- `k6 run infrastructure/load/catalog.js` and `infrastructure/load/checkout.js` against **staging**.
- `node apps/storefront/e2e/smoke-journey.cjs` after each staging deploy (demo data + sandbox gateway).
