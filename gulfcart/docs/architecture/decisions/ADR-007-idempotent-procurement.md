# ADR-007: Automated procurement with idempotency

**Status:** Accepted

**Context.** Purchasing from a supplier spends real money. Queue redelivery, worker crashes
and network timeouts can otherwise cause duplicate purchases.

**Decision.** One supplier order per supplier per customer order, a unique idempotency key
per purchase, status checks under row lock, an explicit `unknown` outcome followed by
reconciliation, and retries only for errors classified as safe. Full rules in
`PROCUREMENT_WORKFLOW.md`.

**Consequences.** More states and a reconciliation job, in exchange for the guarantee
"total purchases per supplier order = 1".
