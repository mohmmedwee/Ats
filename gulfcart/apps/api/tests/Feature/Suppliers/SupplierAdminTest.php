<?php

namespace Tests\Feature\Suppliers;

use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierVariantMapping;
use Illuminate\Database\QueryException;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class SupplierAdminTest extends TestCase
{
    use CatalogFixtures;

    public function test_supplier_cannot_be_activated_without_written_authorization(): void
    {
        $h = $this->asStaff(['admin']);
        $id = $this->postJson('/api/v1/admin/suppliers', [
            'name' => 'Acme Wholesale', 'code' => 'acme', 'connector_type' => 'mock', 'base_currency' => 'USD',
            'credentials_ref' => 'vault:suppliers/acme',
        ], $h)->assertCreated()
            ->assertJsonPath('data.commercial_authorization_status', 'pending')
            ->assertJsonPath('data.has_credentials', true)
            ->assertJsonMissingPath('data.credentials_ref')
            ->json('data.id');

        $this->assertErrorCode($this->postJson("/api/v1/admin/suppliers/{$id}/status", ['action' => 'activate'], $h), 422, 'SUPPLIER_NOT_AUTHORIZED');

        $this->postJson("/api/v1/admin/suppliers/{$id}/authorizations", [
            'agreement_reference' => 'ACME-2026-01', 'allows_resale' => true, 'allows_api_purchasing' => true,
            'allows_direct_shipping' => true, 'allows_image_use' => false, 'countries' => ['AE', 'SA'],
            'valid_from' => now()->toDateString(),
        ], $h)->assertCreated()->assertJsonPath('data.commercial_authorization_status', 'approved');

        $this->postJson("/api/v1/admin/suppliers/{$id}/status", ['action' => 'activate'], $h)->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson("/api/v1/admin/suppliers/{$id}/status", ['action' => 'revoke'], $h)->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.commercial_authorization_status', 'revoked');
        $this->assertErrorCode($this->postJson("/api/v1/admin/suppliers/{$id}/status", ['action' => 'activate'], $h), 422, 'SUPPLIER_NOT_AUTHORIZED');
    }

    public function test_expired_authorization_does_not_count(): void
    {
        $h = $this->asStaff(['admin']);
        $supplier = Supplier::query()->create(['name' => 'Old', 'code' => 'old', 'connector_type' => 'manual', 'base_currency' => 'USD']);
        $this->postJson("/api/v1/admin/suppliers/{$supplier->id}/authorizations", [
            'agreement_reference' => 'OLD-1', 'allows_resale' => true, 'allows_api_purchasing' => false,
            'allows_direct_shipping' => false, 'allows_image_use' => false, 'countries' => ['AE'],
            'valid_from' => now()->subYear()->toDateString(), 'valid_until' => now()->subDay()->toDateString(),
        ], $h)->assertCreated()->assertJsonPath('data.commercial_authorization_status', 'pending');
    }

    public function test_database_rejects_active_unapproved_supplier(): void
    {
        $this->expectException(QueryException::class);
        Supplier::query()->create(['name' => 'X', 'code' => 'x', 'connector_type' => 'mock', 'base_currency' => 'USD', 'is_active' => true]);
    }

    public function test_supplier_management_requires_permission(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/admin/suppliers', $this->asStaff(['catalog_manager'])), 403, 'FORBIDDEN');
    }

    public function test_mapping_a_supplier_product_makes_the_product_sellable(): void
    {
        ['product' => $p, 'variant' => $v] = $this->sellableProduct(['AE']);
        // Remove the fixture source; add one through the API instead.
        SupplierVariantMapping::query()->delete();
        app(ListingRefresher::class)->refresh($p->id);
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');

        $supplier = $this->supplier(['AE']);
        $h = $this->asStaff(['catalog_manager']);
        $spId = $this->postJson("/api/v1/admin/suppliers/{$supplier->id}/products", [
            'product_id' => $p->id, 'external_product_id' => 'ACME-1', 'cost_price' => '20.00', 'cost_currency' => 'USD', 'stock_status' => 'in_stock',
        ], $h)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/supplier-products/{$spId}/mappings", ['mappings' => [
            ['variant_id' => $v->id, 'external_variant_id' => 'ACME-1-M'],
        ]], $h)->assertOk();

        $this->getJson('/api/v1/products')->assertJsonCount(1, 'data');
    }

    public function test_mapping_rejects_variants_of_other_products(): void
    {
        ['supplierProduct' => $sp] = $this->sellableProduct(['AE']);
        ['variant' => $other] = $this->sellableProduct(['AE']);

        $this->assertErrorCode($this->putJson("/api/v1/admin/supplier-products/{$sp->id}/mappings", ['mappings' => [
            ['variant_id' => $other->id, 'external_variant_id' => 'X'],
        ]], $this->asStaff(['catalog_manager'])), 422, 'VALIDATION_FAILED');
    }
}
