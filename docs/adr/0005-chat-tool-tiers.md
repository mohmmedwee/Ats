# ADR 0005: Chat agent tool tiers

Status: accepted
Date: 2026-08-30

## Context

The chat agent is the most convenient surface in the product and the most
dangerous one. It is the only place where untrusted text — job descriptions,
fetched pages — sits in the same context window as tool access. A prompt asking
the model not to submit an application is not a control; it is a suggestion to a
component that can be argued with.

ADR 0004 established a single submission path. Chat must not become a second.

## Decision

Three tiers, fixed in code and enforced by the registry at dispatch:

| Tier | Meaning | Execution |
|---|---|---|
| T0 read | reads only | runs immediately |
| T1 prepare | mutates our own data | confirmation card bound to the argument hash |
| T2 external | reaches an employer or changes policy | **cannot be registered** |

`ToolRegistry.register` raises `ExternalTierNotCallableError` for a T2
descriptor, so an external tool cannot exist in the registry even by mistake.
The database carries the same rule as a check constraint on `chat_tool_calls`.
When the model asks for an external action, the registry answers with a deep
link to the UI gate.

Supporting rules:

- Arguments are validated against a Pydantic model before dispatch; invalid
  arguments are a tool error the model can read, never a partial execution.
- The registry injects the caller's user id and discards any the model proposes.
- A T1 confirmation is bound to a hash of the normalised arguments and expires.
- Every dispatch derives an idempotency key from
  `(thread_id, message_id, tool_name, args_hash)`.
- Chat inherits the autonomy level and can never exceed it. At level 0 the
  prepare tier is neither advertised nor dispatchable.

## Consequences

- The guarantee is testable rather than asserted: a test enumerates the registry
  and fails if any tool is T2.
- Adding a genuinely external capability to chat requires changing this ADR and
  the registry, not just adding a tool.
- Some flows need a UI hop that chat alone could have completed. That is the
  intended cost.
