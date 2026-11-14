# GulfCart — Product Requirements Document

Version 1.0 · Status: baseline

## 1. Vision

An independent Gulf storefront that sells products sourced from multiple authorized
international suppliers, priced in local currency with all costs shown before payment,
and delivered directly to the customer. The lasting value is GulfCart's own commerce,
pricing, procurement and shipping layer, which can add suppliers without being rebuilt.
Success must never depend on a single supplier or marketplace.

## 2. Business model

- GulfCart is the **seller** to the consumer. It sets prices, collects payment, issues
  invoices and owns the customer relationship, returns and refunds.
- Each product has one or more **supplier sources**, each with its own cost.
- After payment is confirmed, GulfCart buys from the supplier, which ships directly to the
  customer (or through a consolidation partner).
- One customer order can produce several **supplier orders** and **shipments**.

## 3. Markets and localisation

| | UAE | Saudi Arabia |
|---|---|---|
| Country code | AE | SA |
| Currency | AED | SAR |
| Locales | ar-AE, en-AE | ar-SA, en-SA |
| Prices | VAT-inclusive display | VAT-inclusive display |

Both markets launch together. Country-specific tax, shipping, customs, return policy and
address rules are configuration, not code.

## 4. Users and roles

| Role | Description |
|---|---|
| Customer | Browses, buys, tracks, returns |
| Guest | Browses and builds a cart; must register or verify phone at checkout (MVP) |
| Support agent | Views orders and customers, opens returns, adds notes |
| Operations | Procurement queue, manual purchasing, shipment issues |
| Catalog manager | Products, categories, images, supplier mappings, publishing |
| Finance | Refunds, payouts, reports, pricing rules |
| Admin | Users, roles, suppliers, countries, system settings |
| Super admin | Everything, including role management. MFA mandatory |

All staff roles require MFA.

## 5. Functional scope (MVP)

1. **Catalog**: categories, products, variants (size, colour, ...), images with usage-rights
   status, per-country availability, bilingual content.
2. **Supplier management**: suppliers, authorization status, connector type, supplier
   products and variant mappings, CSV import, mock connector.
3. **Pricing**: exchange rates, pricing rules (global, country, supplier, category,
   product override), minimum margin, VAT-inclusive rounding, quote validity.
4. **Cart and checkout**: cart, address book (AE/SA), shipping options, full quote with
   taxes and duties, quote expiry, revalidation of stock and price.
5. **Orders and payments**: transactional order creation, payment gateway, webhooks,
   order splitting by supplier.
6. **Procurement**: automated purchase through connectors, idempotency, retries,
   exceptions queue, manual fallback, admin dashboard.
7. **Refunds**: full and partial refunds linked to payments.

Post-MVP: courier integrations, live tracking, notifications (email, SMS, WhatsApp),
returns portal, reporting, promotions engine, loyalty.

## 6. Non-functional requirements

| Area | Requirement |
|---|---|
| Availability | 99.5% monthly for storefront and API at launch |
| Performance | p95 catalog API < 300 ms; p95 checkout quote < 800 ms (excluding supplier calls) |
| Correctness | Zero duplicate supplier purchases; refunds never exceed captured amount |
| Security | OWASP ASVS L2; no card data stored (PCI scope SAQ-A) |
| Privacy | PII minimised and encrypted where appropriate; data residency reviewed with counsel |
| Accessibility | WCAG 2.1 AA for storefront |
| Localisation | Full RTL Arabic UI; Arabic and English content |

## 7. Customer promises

- Final price, including shipping, VAT and any duties, is shown before payment.
- One order number even when items arrive in several shipments; the split is shown clearly.
- If a supplier purchase fails or the price rises beyond tolerance, the customer is offered
  a replacement or a refund; GulfCart never silently charges more.

## 8. Launch gates (not engineering tasks)

The 8-week MVP is a software milestone. Commercial launch also requires:

1. Company jurisdiction and licensing decided.
2. VAT registration in the required markets.
3. Importer of Record arrangements for AE and SA.
4. At least one supplier with written authorization for resale and direct shipping.
5. Payment provider approved for both markets.
6. Courier agreements.
7. Consumer protection, returns and privacy policies approved by counsel.

## 9. Out of scope for v1

Marketplace for third-party sellers, mobile native apps, cash on delivery
(to be evaluated), B2B pricing, countries beyond AE and SA.
