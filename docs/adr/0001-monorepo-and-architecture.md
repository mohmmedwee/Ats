# ADR 0001: Monorepo layout and event-driven architecture

Status: accepted
Date: 2026-08-30

## Context

The system in `job-agent-plan.md` has one API, background workers, a web app, and
several domain libraries that all share the same models, enums, and policy
constants. The workflow must be resumable and every node must persist its state
before the next one runs.

Splitting these into separate repositories would let the pieces disagree about
the state machine, which is exactly the failure mode we cannot afford: a
divergent copy of the application status enum is how an application gets
submitted twice.

## Decision

A uv workspace with one distribution per component:

- `packages/domain` owns models, enums, settings, and the state machine. It has
  no dependency on the API or worker, so both must agree with it.
- `packages/{ai,chat,connectors,matching,application_automation,observability}`
  are libraries with a single responsibility each.
- `apps/{api,worker}` are thin: transport, routing, scheduling.
- `apps/web` is a separate npm project.

Cross-node communication is Redis-backed message passing. Each node persists
input, output, status, attempts, and error before handing off.

## Consequences

- One `uv sync --all-packages` sets up every Python component.
- Import direction is enforced by dependency declarations: `domain` cannot
  import from `api`.
- Package names are verbose (`job_agent_domain`). Accepted in exchange for
  unambiguous imports.
- A single lockfile means one dependency upgrade affects everything at once,
  which is the point.
