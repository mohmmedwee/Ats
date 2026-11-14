# Supplier CSV Import

`POST /api/v1/admin/imports/products` (multipart: `supplier_id`, `file`) queues an import.
Poll `GET /api/v1/admin/imports/{id}` for the result.

## Format

UTF-8 CSV (a BOM is accepted), header row required, **one row per variant**, max 5,000 rows.
Rows with the same `external_product_id` form one product.

| Column | Required | Rules |
|---|---|---|
| `external_product_id` | yes | Supplier's product ID |
| `external_variant_id` | yes | Supplier's variant ID |
| `sku` | yes | Our SKU, unique across the catalog, `[A-Za-z0-9._-]` |
| `title_en` | yes | |
| `title_ar` | no | Required later to publish |
| `description_en`, `description_ar` | no | |
| `category_slug` | no | Must exist |
| `brand` | no | |
| `attributes` | no | `size=M;color=Black` |
| `cost_price` | yes | Up to 4 decimals |
| `cost_currency` | yes | ISO 4217 |
| `stock_status` | yes | `in_stock`, `low_stock`, `out_of_stock`, `unknown` |
| `image_url` | no | HTTPS only; downloaded to our storage |
| `weight_grams` | no | 1–100000 |
| `hs_code` | no | 4–12 digits (customs) |
| `countries` | yes | `AE`, `SA` or `AE|SA` |

## Behaviour

- New products are created as **drafts**. Imports never publish, and never overwrite content
  of products that have left draft (only cost, stock and mappings are updated).
- Re-importing the same `external_product_id` updates instead of duplicating.
- Each product is applied in its own transaction: a bad row never leaves a half-imported product.
- Invalid rows are reported with their line number (header = line 1) and skipped.
- Image rights: pre-approved only when the supplier's current authorization has
  `allows_image_use = true` (basis `supplier_agreement`); otherwise they wait for review.
