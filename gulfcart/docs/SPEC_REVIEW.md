# GulfCart — Specification Review

Engineering review of the v1.0 draft. Items under "Corrections" are already applied in the
documents. Items under "Decisions needed" block specific work and are listed with the
milestone they block.

## Corrections applied

### Architecture

1. **Nuxt version.** The draft says Nuxt 3. Nuxt 3's maintenance window was scheduled to
   end in January 2026, so a new project in late 2026 should start on Nuxt 4. ADR-003 records
   Nuxt 4 as the recommendation, pending confirmation.
2. **Countries module added.** Country configuration was described but had no module.
   It is now an explicit module that Tax, Shipping, Pricing and Catalog depend on.
3. **Module dependency direction** defined (`TECHNICAL_SPEC.md` §3) so "no circular
   dependencies" can be enforced by an architecture test.
4. **Connector contract uses typed DTOs** instead of `array`, adds `capabilities()` and
   `findOrderByReference()` for reconciliation after unknown outcomes, and a
   `ManualSupplierConnector` for the manual fallback.

### Database

5. `users.role` column removed. RBAC uses permission tables (`spatie/laravel-permission`);
   a single role column cannot express permissions or multiple roles.
6. `supplier_orders` gets `UNIQUE (order_id, supplier_id)` so an order cannot be split into
   two purchase records for the same supplier.
7. `refunds.payment_id` made `NOT NULL` to match the rule "every refund is linked to a payment";
   `refunded_amount` with a `CHECK` added on payments and orders.
8. `product_prices` unique key changed from `(variant, country, supplier_product)` to
   `(variant, country)`. The old key allowed several prices for one variant in one country
   with no rule for which one the customer sees. The chosen source is stored on the row;
   alternates are used during procurement.
9. Exchange rate snapshot (rate and rate ID) added to `order_items` and `supplier_orders`
   ("keep the exchange rate used at payment and purchase").
10. Variant-level supplier cost added to `supplier_variant_mappings` (variants often cost differently).
11. The 15 "additional tables" are designed in full, plus `returns`, `shipment_items` and
    `supplier_authorizations` fields.
12. Status `CHECK` constraints, `updated_at`, translatable `JSONB` names, HS code and weight
    on products (needed for customs and shipping).
13. `carts` supports guests (`guest_token`), but `orders.user_id` stays `NOT NULL`: guests
    register or verify a phone at checkout. Decision recorded in the PRD.

### Workflow

14. **Payment vs order ordering.** The draft created the order after payment, while
    `payments.order_id` is `NOT NULL`. Resolved: the order is created as `pending_payment`
    from a locked checkout session, then the payment attaches to it; the order becomes
    `paid` only through a verified webhook.
15. **Order state machine** split into a customer-facing order aggregate and a supplier
    order machine; order shipping states are derived from supplier orders and shipments.
16. **Unknown outcomes** (timeouts after sending) get an explicit `unknown` state and
    reconciliation, never a blind retry.
17. **Price tolerance** made concrete: default 5% cost increase or the minimum margin,
    whichever is hit first; GulfCart absorbs increases inside tolerance.

### Pricing and money

18. **Payment fee** is charged on the gross (VAT-inclusive) amount, so the formula is
    `P = (C + k) / (1 − m − f(1 + v))`, not `C / (1 − m)`.
19. **VAT.** Output VAT is never a cost; import VAT is a cost only when not recoverable.
    Consumer prices in AE and SA are shown VAT-inclusive, so the checkout quote reports the
    VAT contained in the total rather than adding it on top (the draft's quote added it).
20. **Worked example** recalculated with every input's currency stated. The draft's
    117.34 → 153.00 figures could not be reproduced from its inputs, because the currencies
    of shipping, tax and marketing were not specified.

## Decisions needed

| # | Decision | Owner | Blocks |
|---|---|---|---|
| D1 | ~~Confirm Nuxt 4 instead of Nuxt 3 (ADR-003)~~ **Resolved: Nuxt 4** | Tech lead | — |
| D2 | Payment provider(s) for AE and SA (must support AED, SAR, mada, Apple Pay) | Business | Sprint 3 |
| D3 | Courier / consolidation partner and DDP vs DDU | Operations | Sprint 2 (shipping), Sprint 4 |
| D4 | Guest checkout policy (current: phone verification required) | Product | Sprint 2 |
| D5 | Cash on delivery: in or out of MVP (current: out) | Business | Sprint 3 |
| D6 | Hosting region and data residency | Legal + tech | Production |
| D7 | Price tolerance default (5%) and minimum margin per category | Finance | Sprint 3 |

## Open business decisions

| Decision | Status | Required action |
|---|---|---|
| Company jurisdiction | Open | Legal and tax comparison (Jordan, UAE, KSA, other) |
| Supplier agreements | Open | Obtain written commercial authorization for resale, API purchasing, direct shipping and image use |
| Importer of Record | Open | Establish UAE and Saudi import arrangements |
| VAT registration | Open | Depends on jurisdiction and thresholds |

Recommendation: do not build real purchasing automation before suppliers are confirmed.
Build the connector framework and the mock supplier first; add real suppliers after approval.
