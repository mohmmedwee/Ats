# ADR 0007: Deduplication order and per-source failure isolation

Status: accepted
Date: 2026-08-30

## Context

The same role reaches us more than once. A company posts to Greenhouse and
mirrors it on its careers page; two sources point at the same board; a board
reformats a posting and it looks new. Showing one job three times makes the
review queue useless. Merging two jobs that are genuinely different is worse:
the one that disappears is never applied to.

Separately, discovery runs across several boards at once. Any of them can be
down, rate-limit us, or return something unexpected. A run that aborts on the
first failure delivers nothing.

## Decision

### Deduplication is ordered, and merging needs confidence

Rules are tried strongest first and stop at the first match:

| Rule | Confidence | Effect |
|---|---:|---|
| Same source + external id | 1.00 | merge |
| Same canonical URL | 0.97 | merge |
| Same content fingerprint | 0.92 | merge |
| Same company + normalised title + location | 0.75 | **link, do not merge** |

The merge threshold is 0.90. The last rule sits below it deliberately: a company
can post two genuinely different roles that reduce to the same company, title,
and location, so those are linked as possible duplicates and hidden from the
default job list, where a person can still see them.

Canonical URLs drop tracking parameters, so the same posting shared through
three channels is one URL. Fingerprints are taken over folded text, so
reformatting does not create a new job.

### Every source fails alone

Each source runs inside its own try block and commits separately, so one
board's failure cannot roll back another's ingested jobs. A failure records the
error, increments a counter, and sets a backoff window that grows with
consecutive failures. While the window is open the source is skipped rather than
retried. The first success resets all of it.

Retries inside a single fetch are bounded and only for signals that mean "busy
or briefly broken": 408, 425, 429, 5xx, and timeouts. A 404 for a board name
that does not exist is an answer, not a blip, and is not retried. `Retry-After`
wins over our own backoff when a board sends it.

### Every normalised field is traceable

Each fetch stores the raw payload, its source URL, fetch time, and content hash,
one row per distinct content. A wrong location can always be attributed: parser
bug, or the board actually said that.

## Consequences

- Re-running discovery is free and safe; the second run creates nothing.
- A board that goes down for a day costs one failed run, not a hammering.
- Linked duplicates accumulate and need a review affordance eventually; for now
  they are simply hidden by default and visible on request.
- The company/title/location rule will occasionally link two distinct roles.
  That is the intended direction to be wrong in.
