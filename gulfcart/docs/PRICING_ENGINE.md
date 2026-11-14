# GulfCart — Pricing Engine Specification

The pricing engine guarantees that every sale earns a real contribution margin.
It is a pure, deterministic domain service: same inputs, same output, fully unit-tested.

## 1. Definitions

| Term | Meaning |
|---|---|
| Product cost | Supplier cost for the variant, converted to the sale currency |
| Shipping cost | What GulfCart pays to deliver (supplier shipping or courier) |
| Customs duty | Non-recoverable duty, per `customs_rules` (may be 0 below a de minimis threshold) |
| Import VAT | VAT on import. **Cost only if non-recoverable** for GulfCart's registration status |
| Output VAT | VAT charged to the customer. **Never a cost**; collected and remitted |
| Payment fee | Percentage plus fixed fee charged on the **gross** amount the customer pays |
| Operational allocation | Per-unit packaging, support, marketing allocation |
| Variable cost `C` | Product + shipping + duty + non-recoverable import VAT + operational allocation |
| Net price `P` | Selling price excluding output VAT |
| Gross price `G` | Price shown to the customer, `G = P × (1 + v)` |
| Contribution margin | `(P − C − fee) / P` |

## 2. Formula

With target margin `m`, payment fee rate `f`, fixed fee `k` and VAT rate `v`:

```text
fee = f × G + k = f × P × (1 + v) + k

P − C − fee = m × P
=> P = (C + k) / (1 − m − f × (1 + v))
G = round_up(P × (1 + v), rounding_rule)
```

The denominator must be positive; otherwise the configuration is rejected.
After rounding, the engine recomputes the actual margin from `G` and asserts it is at
least `min_margin_percent`.

Simple case without fees or VAT: cost 35, margin 20%, `P = 35 / 0.8 = 43.75`.

## 3. Worked example (UAE)

Inputs: product 24.00 USD, shipping 12.00 USD, duty 0, marketing allocation 4.00 USD,
USD/AED 3.6725, fee 2.9% + 0, margin 20%, UAE VAT 5%, rounding up to whole AED.

```text
C   = (24 + 12 + 4) × 3.6725            = 146.90 AED
P   = 146.90 / (1 − 0.20 − 0.029 × 1.05) = 190.89 AED
G   = 190.89 × 1.05 = 200.44 -> round up = 201.00 AED (VAT inclusive)
Check: P = 201 / 1.05 = 191.43; fee = 0.029 × 201 = 5.83
       contribution = 191.43 − 146.90 − 5.83 = 38.70 -> margin 20.2%  ✓
```

The same product in Saudi Arabia uses SAR, the SA rule set and 15% VAT, producing a
different gross price. Never derive one country's price from the other's.

## 4. Inputs and outputs

```json
{
  "variant_id": "…",
  "country_code": "AE",
  "supplier_product_id": "…",
  "supplier_cost": { "amount": "24.00", "currency": "USD" },
  "shipping_cost": { "amount": "12.00", "currency": "USD" },
  "operational_allocation": { "amount": "4.00", "currency": "USD" },
  "quantity": 1
}
```

```json
{
  "currency": "AED",
  "variable_cost": "146.90",
  "net_price": "191.43",
  "vat_amount": "9.57",
  "gross_price": "201.00",
  "payment_fee": "5.83",
  "contribution": "38.70",
  "contribution_margin_percent": "20.22",
  "exchange_rate": { "pair": "USD/AED", "rate": "3.67250000", "rate_id": "…" },
  "rule_ids": ["…"],
  "valid_until": "2026-11-14T16:00:00Z"
}
```

Internal outputs (cost, margin, rule IDs) are admin-only. The storefront receives only
`gross_price` and currency.

## 5. Rule resolution

Rules are matched from most to least specific; the first active match wins for markup or
margin; `min_margin_percent` and `max_selling_price` are taken as the strictest across all
matching levels.

1. Product override
2. Category (nearest ancestor first)
3. Supplier
4. Country
5. Global default

Rule types: `margin_percent` (target margin, formula above), `markup_percent` (on `C`),
`fixed_markup`. Also supported: maximum selling price, promotional discounts (applied at
cart level, never below `min_margin_percent` unless finance approves the campaign), quote
expiration, and price-change alerts (admin notified when recalculated `G` moves by more
than a configurable percentage).

## 6. Exchange rates

- Rates stored in `exchange_rates`, refreshed by the scheduler from a configured provider.
- Pricing applies an FX buffer (`pricing.fx_buffer_percent`, default 1.5%) on cost conversion.
- The rate row used is referenced in every quote, order item and supplier order.
- AED and SAR are pegged to USD, but rates are still stored, not hard-coded.
- Stale rate (> 24 h): pricing stops publishing new prices and alerts finance.

## 7. Rounding

Per-country rounding rule in country config: `ceil_whole`, `ceil_x9` (e.g. 199, 209),
`ceil_0_05`. Rounding always goes up so margin is never reduced by rounding.

## 8. Open points to confirm with tax advisors

- Recoverability of import VAT for GulfCart's chosen structure in each country.
- Whether shipping charged to the customer is included in the VAT base (normally yes).
- Customs duty base (CIF value) and de minimis thresholds per country and shipping mode.
- Whether duties are collected at checkout (DDP) or by the courier on delivery (DDU). DDP is the baseline.
