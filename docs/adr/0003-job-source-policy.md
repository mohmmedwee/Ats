# ADR 0003: Job source policy

Status: accepted
Date: 2026-08-30

## Context

Job data can be obtained from public APIs, from public pages, or by driving a
logged-in browser session. These differ in reliability, in load placed on the
source, and in whether they are acceptable at all.

## Decision

- Prefer documented public APIs: Greenhouse, Lever, Ashby.
- Generic RSS/JSON feeds and a user-managed career-page watchlist come next.
- Browser automation is used only for applying, never for bulk discovery.
- Authenticated LinkedIn scraping is out of scope, permanently.
- Every source is rate limited and backed off per source. A failing source is
  isolated: it must not stop the others.
- Every raw response is snapshotted with source URL, fetch time, external id,
  and content hash, so any normalised field is traceable to what was fetched.

## Consequences

- Coverage is narrower than a scraper's, and deliberately so.
- Adding a source is a connector implementing `JobSource`, plus contract tests
  against recorded fixtures — no live calls in CI.
- Deduplication can rely on `(source_id, external_id)` being meaningful, which
  is enforced as a unique constraint.
