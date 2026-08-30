# ADR 0002: OpenAI-compatible provider interface

Status: accepted
Date: 2026-08-30

## Context

The deployment is local-first. Which model runs depends on the machine: Ollama
on Linux and Windows, MLX-LM on Apple Silicon, possibly a hosted endpoint later.
Business logic must not care.

Separately, model output is not trustworthy input. It can be malformed, it can
be confidently wrong, and it must never directly cause an external action.

## Decision

- All model access goes through the `AIProvider` protocol in `packages/ai`:
  `complete`, `stream`, `embed`, `aclose`.
- The default implementation targets any OpenAI-compatible `/chat/completions`
  endpoint, which covers Ollama, MLX-LM, vLLM, and hosted APIs.
- Structured output goes through `generate_structured`, which validates against
  a Pydantic schema and retries once by feeding the validation error back. A
  response that does not validate raises; it is never partially accepted.
- Tests use `FakeProvider`, whose embeddings are hash-derived, so tests are
  deterministic and no test requires a model to be installed.

## Consequences

- Swapping models is configuration (`AI_PROVIDER`, `AI_BASE_URL`, `AI_MODEL`).
- Chat requires reliable tool calling. Where a model's tool calling is
  unreliable, the fallback is constrained JSON tool selection, not free-form
  text parsing — `ToolCallingUnsupportedError` marks that boundary.
- We accept a lowest-common-denominator API surface and give up
  provider-specific features.
