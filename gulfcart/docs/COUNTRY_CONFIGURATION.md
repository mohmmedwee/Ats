# GulfCart — Country Configuration

UAE and Saudi Arabia launch together. Country behaviour is **data**, not code.

## 1. Rule

Do not scatter branches like this through the codebase:

```php
if ($country === 'SA') {
    // tax logic
}
```

Instead, business code asks a configuration service:

```php
$ctx = $countries->context('SA');           // CountryContext value object
$vat = $taxRules->vatFor($ctx, $category, $date);
$methods = $shipping->methodsFor($ctx, $cart);
$ctx->rounding()->apply($grossPrice);
```

The only place allowed to contain per-country logic in code is a country **strategy** for
something that genuinely cannot be data (for example a national address validator),
registered by country code in a service provider.

## 2. Configuration matrix

| Configuration | UAE | Saudi Arabia | Where it lives |
|---|---|---|---|
| Country code | AE | SA | `countries.code` |
| Currency | AED | SAR | `countries.currency_code` |
| VAT baseline | 5% | 15% | `tax_rules` (effective-dated) |
| Locales | ar-AE, en-AE | ar-SA, en-SA | `countries.supported_locales` |
| Timezone | Asia/Dubai | Asia/Riyadh | `countries.timezone` |
| Phone format | +971 | +966 | `countries.settings.phone` |
| Address validation | Emirate + area | Region + city + optional National Address short code | address strategy |
| Shipping providers | Country-specific | Country-specific | `shipping_methods` |
| Customs | Separate rules | Separate rules | `customs_rules` |
| Importer of Record | Legal setup required | Legal setup required | `countries.settings.importer_of_record` |
| Return policy | Country-specific | Country-specific | `countries.settings.returns` |
| Price rounding | e.g. `ceil_whole` | e.g. `ceil_whole` | `countries.settings.rounding` |
| Payment methods | e.g. cards, Apple Pay | e.g. cards, mada, Apple Pay | `countries.settings.payment_methods` |

VAT rates and thresholds must be confirmed by a tax advisor before launch and are
editable in admin without a deployment. Rule changes are effective-dated and audited.

## 3. Country context resolution

1. Explicit `X-Country` header or `?country=` parameter.
2. The cart's country.
3. The customer's default address country.
4. Fallback: `AE`.

A cart, checkout session and order are bound to one country and currency. Changing the
country creates a new cart context; prices are never converted at display time.

## 4. Seed data (Milestone 1)

```php
['code' => 'AE', 'currency_code' => 'AED', 'default_locale' => 'ar-AE',
 'supported_locales' => ['ar-AE', 'en-AE'], 'timezone' => 'Asia/Dubai'],
['code' => 'SA', 'currency_code' => 'SAR', 'default_locale' => 'ar-SA',
 'supported_locales' => ['ar-SA', 'en-SA'], 'timezone' => 'Asia/Riyadh'],
```

Tax, customs and shipping seeds arrive in Sprint 2.

## 5. Company jurisdiction (deferred)

No code may assume where the company is incorporated. Before choosing (Jordan, UAE, KSA
or other), compare: place of incorporation and of effective management, tax registration,
e-commerce licensing in each market, VAT registration obligations, who is the legal seller
to the consumer, who is Importer of Record, courier agreements, and consumer-protection
obligations. Having an online store in one country does not exempt the company from
licensing requirements in the markets it sells into.

Engineering impact: seller legal name, VAT numbers, invoice format (for example, e-invoicing
requirements in KSA) and IOR are configuration values per country, filled once decided.
