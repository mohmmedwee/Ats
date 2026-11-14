<?php

namespace Tests\Feature\Suppliers;

use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Contracts\SupplierConnector;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Domain\Connector\PurchaseLine;
use App\Modules\Suppliers\Domain\Connector\PurchaseRequest;
use App\Modules\Suppliers\Domain\Connector\PurchaseResult;
use App\Modules\Suppliers\Domain\Connector\TransientConnectorException;
use App\Modules\Suppliers\Domain\Connector\UnknownOutcomeException;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class MockConnectorTest extends TestCase
{
    use CatalogFixtures;

    /** @return array{0: SupplierConnector, 1: PurchaseRequest, 2: Supplier} */
    private function setUpPurchase(array $mock = []): array
    {
        $supplier = $this->supplier(['AE']);
        $supplier->update(['metadata' => $supplier->metadata + ['mock' => $mock]]);
        ['supplierProduct' => $sp] = $this->sellableProduct(['AE'], ['supplier' => $supplier]);
        $mapping = $sp->mappings()->firstOrFail();
        $request = new PurchaseRequest('SO-1', [new PurchaseLine($sp->external_product_id, $mapping->external_variant_id, 2, '24.0000', 'USD')], ['country' => 'AE']);

        return [app(ConnectorResolver::class)->forSupplier($supplier->id), $request, $supplier];
    }

    public function test_orders_are_idempotent_per_key(): void
    {
        [$connector, $request] = $this->setUpPurchase();

        $first = $connector->createOrder($request, 'so_1_v1');
        $second = $connector->createOrder($request, 'so_1_v1');

        $this->assertSame(PurchaseResult::ACCEPTED, $first->outcome);
        $this->assertSame($first->externalOrderId, $second->externalOrderId);
        $this->assertSame('72.0000', $first->totalCost); // 2 × 24 + 2 × 12 shipping
        $this->assertDatabaseCount('mock_supplier_orders', 1);
    }

    public function test_reject_and_transient_modes(): void
    {
        [$connector, $request] = $this->setUpPurchase(['purchase_mode' => 'reject']);
        $this->assertSame(PurchaseResult::REJECTED, $connector->createOrder($request, 'k1')->outcome);

        [$connector, $request] = $this->setUpPurchase(['purchase_mode' => 'transient']);
        $this->expectException(TransientConnectorException::class);
        $connector->createOrder($request, 'k2');
    }

    public function test_unknown_outcome_can_be_reconciled(): void
    {
        [$connector, $request] = $this->setUpPurchase(['purchase_mode' => 'unknown_then_accept']);
        try {
            $connector->createOrder($request, 'k3');
            $this->fail('Expected an unknown outcome.');
        } catch (UnknownOutcomeException) {
        }
        $this->assertNotNull($connector->findOrderByReference('k3'));

        [$connector, $request] = $this->setUpPurchase(['purchase_mode' => 'unknown_then_missing']);
        try {
            $connector->createOrder($request, 'k4');
        } catch (UnknownOutcomeException) {
        }
        $this->assertNull($connector->findOrderByReference('k4'));
    }

    public function test_price_change_and_stock_simulation_flow_through_sync(): void
    {
        $supplier = $this->supplier(['AE']);
        ['product' => $p, 'supplierProduct' => $sp] = $this->sellableProduct(['AE'], ['supplier' => $supplier]);
        $mapping = $sp->mappings()->firstOrFail();
        $supplier->update(['metadata' => $supplier->metadata + ['mock' => ['cost_change_percent' => 10, 'out_of_stock' => []]]]);

        $this->postJson("/api/v1/admin/supplier-products/{$sp->id}/sync", [], $this->asStaff(['catalog_manager']))
            ->assertOk()->assertJsonPath('data.changed', 1)->assertJsonPath('data.mappings.0.cost_price', '26.4000');

        $supplier->update(['metadata' => ['mock' => ['out_of_stock' => [$mapping->external_variant_id]]] + $supplier->metadata]);
        $this->artisan('suppliers:sync')->assertSuccessful();
        $this->assertSame('out_of_stock', $mapping->refresh()->stock_status);
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    }

    public function test_mock_is_refused_in_production(): void
    {
        $supplier = $this->supplier(['AE']);
        $this->app['env'] = 'production';

        $this->expectException(ConnectorException::class);
        app(ConnectorResolver::class)->forSupplier($supplier->id);
    }
}
