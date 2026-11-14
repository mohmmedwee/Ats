# ADR-010: Transactional outbox for async events

**Status:** Accepted

**Context.** State changes (order paid, supplier order purchased) must reliably trigger
work in other modules and external systems. Dispatching a queue job after commit can be
lost on crash; dispatching before commit can act on data that rolls back.

**Decision.** Write domain events to `outbox_events` in the same transaction as the state
change. A dispatcher worker publishes pending events to Laravel's event bus/queues and
marks them dispatched. Consumers are idempotent (at-least-once delivery).

**Consequences.** Events are never lost or premature. Slight latency (polling interval,
default 1 s) and a table that needs periodic pruning.
