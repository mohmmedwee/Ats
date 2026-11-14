# ADR-004: PostgreSQL as primary database

**Status:** Accepted

**Context.** Financial integrity needs strong constraints, transactions, row locking and
flexible fields for supplier data and translations.

**Decision.** PostgreSQL 17 as the single source of truth. `NUMERIC` money, `JSONB` for
variable data, `CHECK` constraints for statuses, `SELECT … FOR UPDATE SKIP LOCKED` for
work queues, `pg_trgm` for search in the MVP.

**Consequences.** Tests must run on PostgreSQL, not SQLite. A dedicated search engine
(Meilisearch or OpenSearch) can be added later behind a Catalog search contract.
