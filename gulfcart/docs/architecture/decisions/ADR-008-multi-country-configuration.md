# ADR-008: Multi-country configuration

**Status:** Accepted

**Context.** AE and SA launch together with different currency, VAT, customs, shipping,
address formats and policies; more GCC countries may follow.

**Decision.** A Countries module provides a `CountryContext`; tax, customs, shipping,
pricing and policy rules are effective-dated data editable in admin. Country-specific
code exists only as registered strategies where data is not enough.

**Consequences.** Adding a country is mostly configuration. Rule changes need audit and
tests based on configured fixtures rather than hard-coded rates.
