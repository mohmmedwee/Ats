# ADR-006: Supplier Connector abstraction

**Status:** Accepted

**Context.** Products come from several suppliers whose APIs (if any) differ. No
marketplace can be assumed to offer a commercial purchasing API, and unauthorized
scraping or browser automation is not acceptable.

**Decision.** All supplier access goes through `SupplierConnectorInterface` with typed DTOs
and declared capabilities. Implementations: `MockSupplierConnector`, `ManualSupplierConnector`
and one `Authorized…Connector` per supplier with written authorization. A shared contract
test suite must pass for every implementation. Order and pricing logic never reference
supplier names.

**Consequences.** New suppliers are added without changing order logic. Until real
agreements exist, operations run on mock (testing) and manual (production) connectors.

**Amendment (Sprint 1).** The contract lives in the Suppliers module as
`App\Modules\Suppliers\Contracts\SupplierConnector` (not in Procurement), with typed DTOs in
`Suppliers\Domain\Connector`. Suppliers owns every supplier integration (catalog sync and
purchasing); Procurement depends on Suppliers, never the reverse. Implementations are resolved
from `suppliers.connector_type`: `mock`, `manual`, or a class registered in
`config('gulfcart.supplier_connectors')`. The mock connector is refused in production unless
`ALLOW_MOCK_SUPPLIER_IN_PRODUCTION=true`.
