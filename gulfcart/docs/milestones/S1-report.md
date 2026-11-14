# Sprint 1 — Catalog & Supplier Management: Report

Scope: GC-011 … GC-020. Result: **implemented**, 104 backend tests passing.

| ID | Task | Result |
|---|---|---|
| GC-011 | Categories CRUD | Bilingual tree, cycle prevention, safe delete |
| GC-012 | Products CRUD | Draft → pending_review → published → unpublished/archived state machine; publish checks |
| GC-013 | Variants | Unique SKU, attributes, per-variant weight |
| GC-014 | Supplier CRUD | Pending → authorization recorded → active; DB constraint forbids active unapproved suppliers |
| GC-015 | Supplier products | Product links, variant mappings, per-variant cost/stock, priority |
| GC-016 | CSV import | Async, per-product transactions, row-level errors ([`SUPPLIER_IMPORT.md`](../SUPPLIER_IMPORT.md)) |
| GC-017 | Image rights | Pending until approved; only approved, locally stored images are shown or allow publishing |
| GC-018 | Search | Trigram index over English/Arabic title and brand; category filter includes subcategories |
| GC-019 | Country availability | Per-country availability + listing read model (`is_sellable`, reason, from price) |
| GC-020 | Mock connector | Idempotent orders, reject/transient/unknown-outcome modes, price change and stock simulation |

## Decisions

1. **Connector contract in Suppliers** (ADR-006 amendment) so Procurement depends on Suppliers, not the reverse.
2. **Listing read model.** Whether a product is sellable in a country is computed by Catalog from
   rules other modules register (`SellabilityRule`: authorized source from Suppliers, price from
   Pricing in Sprint 2) and stored on `product_country_availability`. The storefront query never
   joins other modules' tables. Modules raise `CatalogListingStale` when their data changes.
3. **Images are copied, never hotlinked.** URL images are downloaded by a queued job (HTTPS only,
   JPEG/PNG/WebP, ≤ 10 MB, content verified as a real image).
4. **Imports never publish** and never overwrite content of products that left draft.
5. **Mock connector refused in production** unless explicitly enabled.
6. **No ext-bcmath dependency**: `App\Shared\Money\Decimal` wraps `brick/math`.

## Bug found

Two routes shared `POST admin/products/{product}/{action}`; Laravel kept only the last, silently
removing submit/archive/return-to-draft. Merged into one route with the publish permission
checked in the controller; covered by the lifecycle test.
