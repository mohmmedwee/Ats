# ADR-005: Redis for queues and cache

**Status:** Accepted

**Context.** Need asynchronous procurement, webhooks, notifications and caching of
configuration and catalog reads.

**Decision.** Redis 7 for queues (named queues), cache, sessions and locks, with AOF
persistence. Redis is never the source of truth for financial state; every job can be
rebuilt from PostgreSQL (procurement tasks, outbox).

**Consequences.** Losing Redis delays work but does not lose orders or payments.
