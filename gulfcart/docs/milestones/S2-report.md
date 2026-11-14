# Sprint 2 — Pricing & Shopping: Report

Scope: GC-021 … GC-030. Result: **implemented** (storefront pages arrive with Sprint 3's UI work).
Backend: 138 tests passing.

| ID | Task | Result |
|---|---|---|
| GC-021 | Exchange rates | Snapshot table, provider interface, `fx:refresh` hourly, staleness takes products off sale |
| GC-022 | Pricing engine | Pure engine; documented example reproduced exactly; margin re-checked after rounding |
| GC-023 | Country pricing | Price per variant and country in AED/SAR, cheapest authorized source, VAT per country/category |
| GC-024 | Shipping quotes | Flat and weight-band methods per country, free threshold, delivery-day adjustments |
| GC-025 | Tax configuration | Effective-dated VAT/import VAT with category overrides; customs with HS prefixes and de minimis |
| GC-026 | Product storefront | API complete (Sprint 1 + prices); Nuxt pages delivered with the Sprint 3 UI |
| GC-027 | Shopping cart | Guest token carts, merge on sign-in, per-country carts, limits |
| GC-028 | Checkout quote | Locked 30-minute sessions with full internal quote; customer view hides costs |
| GC-029 | Address management | AE and SA rules, phone normalization, defaults, privacy |
| GC-030 | Cart validation | PRICE_CHANGED / OUT_OF_STOCK / UNAVAILABLE issues; live supplier stock check at session creation |

## Decisions

See `COUNTRY_CONFIGURATION.md` §6 and `PRICING_ENGINE.md` §9. In short: VAT-inclusive prices,
per-order customer shipping fee, DDP customs per supplier shipment, guests sign in to check out,
cheapest authorized source wins, stale FX means no price.

## Bugs found by tests

1. Rule changes were ignored by a long-lived `RuleResolver` cache → the repricer now reloads rules.
2. Phone numbers typed with spaces failed validation → input is cleaned before validation.
3. Seeders read another module's tables → switched to the `CountryDirectory` contract (caught by the boundary test).
