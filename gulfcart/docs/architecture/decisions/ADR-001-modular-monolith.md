# ADR-001: Modular monolith instead of microservices

**Status:** Accepted

**Context.** Small team, one product, strong consistency needs across orders, payments and
procurement. Microservices would add distributed transactions, network failure modes and
operational cost before there is a scaling need.

**Decision.** One Laravel application with modules under `app/Modules`. Modules talk via
contracts and domain events; no module touches another's tables. Boundaries are enforced
by architecture tests.

**Consequences.** Simple deployment and local transactions. Extracting a module later
(for example Procurement) is possible because the boundaries and events already exist.
Discipline is required: boundary tests run in CI.
