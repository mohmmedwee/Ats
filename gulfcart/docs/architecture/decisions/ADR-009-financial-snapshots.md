# ADR-009: Snapshot-based financial records

**Status:** Accepted

**Context.** Product prices, costs, exchange rates and tax rules change over time, but
invoices, refunds and reports must reflect what was true at the time of sale.

**Decision.** Orders, order items and supplier orders store immutable snapshots: product
data, prices, taxes, costs, exchange rate and pricing calculation. Corrections are new rows
(refunds, adjustments), never edits.

**Consequences.** Some data duplication; historical reports are exact and auditable.
