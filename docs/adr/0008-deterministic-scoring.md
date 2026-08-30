# ADR 0008: The score is deterministic; the model only explains it

Status: accepted
Date: 2026-08-30

## Context

A match score decides what the candidate sees first and what they spend an
evening applying to. It is the most consequential number in the product.

An LLM would produce a plausible number for any job in one call. It would also
produce a slightly different number tomorrow for the same job, be impossible to
justify line by line, and be argued into a higher score by a posting that asks
it to.

## Decision

The score is computed by pure functions over six weighted dimensions:

| Dimension | Weight |
|---|---:|
| Role and responsibility fit | 25% |
| Required technical skills | 25% |
| Seniority and experience | 15% |
| Architecture and cloud | 15% |
| Leadership and domain | 10% |
| Location, authorisation, compensation | 10% |

Supporting rules:

- **Only verified facts justify a score.** A fact with provenance
  `generated_draft` is excluded. A skill the user has not confirmed cannot raise
  a number that leads to an application.
- **Every matched and missing requirement carries a reference.** `fact:<uuid>`
  for something the candidate has, `job:<uuid>#field` for something the posting
  says. These are stored as rows, not buried in JSON, so "roles whose only gap
  is Kubernetes" is a query.
- **Hard blockers reject but do not erase.** A rejected job keeps its score: an
  88 blocked on location is a different thing from an 88 blocked on pay, and the
  user may disagree with the filter.
- **Silence is never a "no".** A posting that does not mention sponsorship or
  salary is scored as uncertain and surfaced as a question to ask, not rejected.
- **Embeddings are a bounded signal.** Semantic similarity can move at most 35%
  of one dimension. It catches roles described in words the CV does not reuse,
  but it cannot be shown to the user as a reason, and this score has to stay
  explainable.
- **Reproducibility is enforced by a hash.** The inputs hash covers the scorer
  version, the weights, the job content hash, the candidate facts, the
  preferences, and the embedding model. Unchanged inputs reuse the stored score
  instead of recomputing it.

The model writes prose about a result it did not compute. Every point it makes
must cite one of the evidence items by index; a point citing nothing valid is
dropped rather than shown as if it were justified. The posting is passed to it
as untrusted content.

## Consequences

- A ranking can be explained line by line, and a user who disagrees can point at
  the specific line.
- `fixtures/jobs/ranking_dataset.json` pins the expected order of a fixed
  dataset. A change that reorders it fails a test, so tuning the scorer is a
  deliberate act rather than a drift.
- The scorer will miss fits that need judgement — an unusual career pivot, a
  role whose value is in its domain rather than its stack. The embedding signal
  and the user's own review are what cover that.
- Weights are hardcoded rather than learned. With one user and no outcome data
  there is nothing to learn from yet; per-user tuning is a later phase.
