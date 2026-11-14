# GulfCart — API Specification

Machine-readable contract: [`api/openapi.yaml`](api/openapi.yaml) (OpenAPI 3.1).
Every endpoint added in code must be added there in the same change.

## 1. Conventions

| Item | Convention |
|---|---|
| Base URL | `https://api.gulfcart.com/api/v1` |
| Protocol / format | HTTPS only, JSON (`application/json`), UTF-8 |
| Customer auth | Sanctum: SPA cookie session for the Nuxt storefront, bearer tokens for future mobile apps |
| Admin auth | Sanctum + TOTP MFA; admin routes under `/api/v1/admin/*` |
| Authorization | RBAC (permissions, not role names, are checked in policies) |
| Pagination | Cursor (`?cursor=…&limit=…`, max 100) for listings; page-based only in admin tables |
| Versioning | URI `/api/v1`; breaking changes require `/api/v2` |
| Context | `X-Country: AE|SA` and `Accept-Language: ar|en` headers (or `?country=`) |
| Idempotency | `Idempotency-Key` header required on `POST /orders`, `/payments/intent`, `/payments/{id}/refund`, `/admin/refunds`, `/admin/procurement/{id}/*` |
| Rate limiting | Per user and per IP; stricter on auth (5/min) and OTP (3/10 min) |
| Request ID | `X-Request-Id` echoed in response header and `meta.request_id` |
| Money | `{ "amount": "89.00", "currency": "AED" }`, amount is a decimal string |
| Dates | ISO 8601 UTC (`2026-11-14T16:00:00Z`) |
| IDs | UUID strings |

### Success envelope

```json
{ "success": true, "data": {}, "meta": { "request_id": "req_123456" } }
```

Listings add `meta.pagination: { "next_cursor": "…", "prev_cursor": null, "limit": 20 }`.

### Error envelope

```json
{
  "success": false,
  "error": { "code": "PRODUCT_OUT_OF_STOCK", "message": "This product is no longer available.", "details": {} },
  "meta": { "request_id": "req_123456" }
}
```

`message` is localized by `Accept-Language`. `code` is stable and documented.

### Error codes (initial catalogue)

| HTTP | Code |
|---|---|
| 400 | `BAD_REQUEST`, `IDEMPOTENCY_KEY_REQUIRED` |
| 401 | `UNAUTHENTICATED`, `MFA_REQUIRED` |
| 403 | `FORBIDDEN` |
| 404 | `NOT_FOUND` |
| 409 | `IDEMPOTENCY_KEY_REUSED` (same key, different body), `ILLEGAL_STATE_TRANSITION`, `CONFLICT` |
| 410 | `QUOTE_EXPIRED` |
| 422 | `VALIDATION_FAILED` (`details.fields`), `PRODUCT_OUT_OF_STOCK`, `PRICE_CHANGED`, `COUNTRY_NOT_SUPPORTED`, `PRODUCT_NOT_AVAILABLE_IN_COUNTRY` |
| 429 | `RATE_LIMITED` |
| 500 / 503 | `INTERNAL_ERROR`, `SERVICE_UNAVAILABLE` |

## 2. Health

| Method | Endpoint | Description |
|---|---|---|
| GET | `/health/live` | Process is up (no dependencies) |
| GET | `/health/ready` | PostgreSQL, Redis and queue reachable |

## 3. Authentication

| Method | Endpoint | Description |
|---|---|---|
| POST | `/auth/register` | Customer registration (email or phone) |
| POST | `/auth/login` | Login |
| POST | `/auth/logout` | Logout |
| POST | `/auth/forgot-password` | Password recovery |
| POST | `/auth/reset-password` | Password reset |
| GET | `/auth/me` | Current user, roles, permissions |
| POST | `/auth/otp/send` | Send phone OTP |
| POST | `/auth/verify-otp` | Verify OTP |
| POST | `/auth/mfa/setup` | Staff: begin TOTP enrolment |
| POST | `/auth/mfa/confirm` | Staff: confirm TOTP |
| POST | `/auth/mfa/challenge` | Staff: complete login with TOTP |

## 4. Catalog (public)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/products` | Listing (`country`, `category`, `q`, `sort`, `cursor`) |
| GET | `/products/{slug}` | Product details |
| GET | `/products/search` | Full-text search |
| GET | `/products/{id}/variants` | Variants with prices for the country |
| GET | `/products/{id}/availability` | Availability and delivery estimate |
| GET | `/categories` | Category tree |
| GET | `/categories/{slug}/products` | Products by category |

Example `GET /api/v1/products?country=AE&category=women`:

```json
{
  "success": true,
  "data": [{
    "id": "0193…",
    "slug": "summer-dress",
    "title": "Summer Dress",
    "price": { "amount": "89.00", "currency": "AED" },
    "availability": "in_stock",
    "delivery_estimate": { "min_days": 7, "max_days": 12 },
    "variants": [{ "id": "0193…", "size": "M", "color": "Black" }]
  }],
  "meta": { "request_id": "req_…", "pagination": { "next_cursor": "…", "limit": 20 } }
}
```

Customer-facing resources never include supplier identity, supplier URLs, supplier SKUs,
cost or margin. An API Resource test enforces this.

## 5. Cart

| Method | Endpoint | Description |
|---|---|---|
| GET | `/cart` | Current cart (guest via cart token, or user) |
| POST | `/cart/items` | Add item `{ variant_id, quantity }` |
| PATCH | `/cart/items/{id}` | Update quantity |
| DELETE | `/cart/items/{id}` | Remove item |
| POST | `/cart/validate` | Revalidate prices and stock; returns per-line changes |

The guest cart is merged into the user cart at login.

## 6. Addresses

| Method | Endpoint | Description |
|---|---|---|
| GET | `/addresses` | List |
| POST | `/addresses` | Create (validated by country rules) |
| PATCH | `/addresses/{id}` | Update |
| DELETE | `/addresses/{id}` | Soft delete |

## 7. Checkout

| Method | Endpoint | Description |
|---|---|---|
| POST | `/checkout/shipping-options` | Shipping options for cart and address |
| POST | `/checkout/quote` | Final price quote (not persisted) |
| POST | `/checkout` | Create checkout session with locked quote |
| GET | `/checkout/{id}` | Checkout session details |

Request:

```json
{
  "country_code": "AE",
  "currency": "AED",
  "address_id": "…",
  "shipping_method": "standard",
  "items": [{ "variant_id": "…", "quantity": 2 }]
}
```

Quote (illustrative values; tax and duties come from configured rules, never a fixed rate):

```json
{
  "currency": "AED",
  "subtotal": "120.00",
  "shipping": "25.00",
  "tax": "6.67",
  "customs": "0.00",
  "discount": "5.00",
  "total": "140.00",
  "prices_include_tax": true,
  "shipments": [{ "items": ["…"], "estimate": { "min_days": 7, "max_days": 12 } }],
  "quote_expires_at": "2026-11-14T16:00:00Z"
}
```

Because storefront prices are VAT-inclusive, `tax` is the VAT **contained** in
`subtotal + shipping − discount` and is not added again:
`total = subtotal + shipping + customs − discount`. In the example, all lines are
standard-rated at 5% (UAE): tax = (120 + 25 − 5) × 5/105 = 6.67 and total = 140.00.

## 8. Orders

| Method | Endpoint | Description |
|---|---|---|
| GET | `/orders` | Customer orders |
| POST | `/orders` | Create order from a valid checkout session (`Idempotency-Key`) |
| GET | `/orders/{id}` | Details, grouped by shipment |
| GET | `/orders/{id}/tracking` | Tracking per shipment |
| POST | `/orders/{id}/cancel` | Cancellation request |
| POST | `/orders/{id}/return` | Return request |

## 9. Payments

| Method | Endpoint | Description |
|---|---|---|
| POST | `/payments/intent` | Create payment intent for an order (`Idempotency-Key`) |
| GET | `/payments/{id}` | Payment status |
| POST | `/webhooks/payments/{provider}` | Provider webhook |

Customer refunds are requested through `/orders/{id}/cancel` or `/orders/{id}/return`;
refunds themselves are executed by staff through `/admin/refunds`.

Webhooks: verify signature, reject timestamps older than 5 minutes, deduplicate by
provider event ID (`payment_webhook_events`), respond 2xx fast and process on a queue.

## 10. Admin

| Method | Endpoint | Description |
|---|---|---|
| GET | `/admin/dashboard` | KPIs |
| GET/POST/PATCH | `/admin/products` | Product management, publishing |
| POST | `/admin/products/import` | CSV import (async job) |
| GET/POST/PATCH | `/admin/categories` | Categories |
| GET/POST/PATCH | `/admin/suppliers` | Supplier management and authorizations |
| GET | `/admin/orders` | Order management |
| GET | `/admin/procurement` | Procurement tasks and exceptions |
| POST | `/admin/procurement/{id}/retry` | Retry an eligible purchase |
| POST | `/admin/procurement/{id}/approve` | Approve manual purchase or price change |
| POST | `/admin/procurement/{id}/switch-source` | Use an alternate supplier |
| GET | `/admin/shipments` | Shipment management |
| GET/POST | `/admin/refunds` | Refunds |
| GET | `/admin/finance/reports` | Financial reports |
| GET/POST/PATCH | `/admin/pricing-rules` | Pricing rules |
| POST | `/admin/pricing/simulate` | Run the pricing engine without saving |
| GET/POST/PATCH | `/admin/countries` | Country configuration |
| GET/POST/PATCH | `/admin/tax-rules`, `/admin/customs-rules`, `/admin/shipping-methods` | Country rule sets |
| GET/POST/PATCH | `/admin/users`, `/admin/roles` | Staff and RBAC |
| GET | `/admin/audit-logs` | Audit trail |

## 11. OpenAPI file layout

```text
docs/api/
├── openapi.yaml          # root: info, servers, security, shared components, $refs
├── authentication.yaml
├── catalog.yaml
├── suppliers.yaml
├── pricing.yaml
├── checkout.yaml
├── orders.yaml
├── payments.yaml
├── procurement.yaml
├── shipping.yaml
└── admin.yaml
```

Each endpoint documents: request schema, response schema, auth requirement, permission,
error codes, validation rules and an example payload. CI validates the file
(`redocly lint`) and a feature test checks that every registered route appears in it.
