<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Application\ProcurementEngine;
use App\Modules\Procurement\Infrastructure\Models\ProcurementTask;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;
use App\Modules\Suppliers\Application\Connectors\MockSupplierConnector;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Infrastructure\Models\MockSupplierOrder;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class ProcurementTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    /** @return array{0: Order, 1: SupplierOrder, 2: array<string, mixed>, 3: array<string, string>} */
    private function paidOrderWithMock(array $mock, array $productOptions = []): array
    {
        $fixture = $this->sellableProduct(['AE'], $productOptions);
        $this->setMock($fixture['supplier'], $mock);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$fixture['variant'], 2]], $auth);
        $this->payOrder($order, $auth);

        return [$order->refresh(), $this->supplierOrders($order)->first(), $fixture, $auth];
    }

    public function test_two_suppliers_give_two_supplier_orders_and_two_purchases(): void
    {
        ['variant' => $a] = $this->sellableProduct(['AE']);
        ['variant' => $b] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$a, 1], [$b, 3]], $auth);
        $this->payOrder($order, $auth);

        $sos = $this->supplierOrders($order);
        $this->assertCount(2, $sos);
        $this->assertSame(['purchased', 'purchased'], $sos->pluck('status')->all());
        $this->assertDatabaseCount('mock_supplier_orders', 2);
        $this->assertSame('processing', $order->refresh()->status);
    }

    public function test_processing_the_same_task_twice_purchases_once(): void
    {
        [, $so] = $this->paidOrderWithMock([]);
        $engine = app(ProcurementEngine::class);

        $this->assertSame('skipped', $engine->process($so->task->id));
        ProcurementTask::query()->whereKey($so->task->id)->update(['status' => 'queued']); // simulate a duplicate queue message
        $this->assertSame('skipped', $engine->process($so->task->id));

        $this->assertSame(1, MockSupplierOrder::query()->count());
        $this->assertSame('succeeded', $so->task->refresh()->status);
    }

    public function test_price_increase_above_tolerance_is_not_purchased(): void
    {
        [$order, $so] = $this->paidOrderWithMock(['cost_change_percent' => 12]);

        $this->assertSame('exception', $so->status);
        $this->assertSame('PRICE_INCREASE_ABOVE_TOLERANCE', $so->exception_reason);
        $this->assertDatabaseCount('mock_supplier_orders', 0);
        $this->assertSame('paid', $order->status);
    }

    public function test_small_price_increase_within_tolerance_is_absorbed(): void
    {
        [, $so] = $this->paidOrderWithMock(['cost_change_percent' => 3]);

        $this->assertSame('purchased', $so->status);
        $this->assertGreaterThan((float) $so->expected_cost, (float) $so->actual_cost);
    }

    public function test_out_of_stock_at_purchase_time_raises_an_exception(): void
    {
        $fixture = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$fixture['variant'], 1]], $auth);
        $this->setMock($fixture['supplier'], ['out_of_stock' => [$fixture['supplierProduct']->mappings()->value('external_variant_id')]]);
        $this->payOrder($order, $auth);

        $this->assertSame('OUT_OF_STOCK', $this->supplierOrders($order)->first()->exception_reason);
    }

    public function test_rejected_orders_raise_an_exception(): void
    {
        [, $so] = $this->paidOrderWithMock(['purchase_mode' => 'reject']);

        $this->assertSame('SUPPLIER_REJECTED', $so->exception_reason);
        $this->assertSame('exception', $so->task->status);
        $this->assertDatabaseHas('procurement_attempts', ['action' => 'submit', 'outcome' => 'rejected']);
    }

    public function test_transient_failures_are_retried_with_backoff(): void
    {
        [, $so, $fx] = $this->paidOrderWithMock(['purchase_mode' => 'transient']);

        $this->assertSame('procurement_pending', $so->status);
        $this->assertSame('waiting_retry', $so->task->status);
        $this->assertTrue($so->task->next_attempt_at->isFuture());

        $this->setMock($fx['supplier'], ['purchase_mode' => 'accept']);
        $this->artisan('procurement:run')->assertSuccessful();
        $this->assertSame('waiting_retry', $so->task->refresh()->status, 'not due yet');

        $this->travel(2)->minutes();
        $this->artisan('procurement:run')->assertSuccessful();
        $this->assertSame('purchased', $so->refresh()->status);
        $this->assertDatabaseCount('mock_supplier_orders', 1);
    }

    public function test_unknown_outcome_is_reconciled_without_buying_twice(): void
    {
        [, $so, $fx] = $this->paidOrderWithMock(['purchase_mode' => 'unknown_then_accept']);
        $this->assertSame('unknown', $so->status);
        $this->assertSame('reconciling', $so->task->status);

        // Even if the supplier now accepts everything, nothing is resubmitted blindly.
        $this->setMock($fx['supplier'], ['purchase_mode' => 'accept']);
        $this->artisan('procurement:run')->assertSuccessful();
        $this->assertSame('unknown', $so->refresh()->status);

        $this->travel(6)->minutes();
        $this->artisan('procurement:reconcile')->assertSuccessful();

        $so->refresh();
        $this->assertSame('purchased', $so->status);
        $this->assertSame(MockSupplierOrder::query()->sole()->external_order_id, $so->external_order_id);
    }

    public function test_missing_order_after_unknown_outcome_is_retried_with_the_same_key(): void
    {
        [, $so, $fx] = $this->paidOrderWithMock(['purchase_mode' => 'unknown_then_missing']);
        $key = $so->idempotency_key;
        $this->setMock($fx['supplier'], ['purchase_mode' => 'accept']);

        $this->travel(6)->minutes();
        $this->artisan('procurement:reconcile')->assertSuccessful();
        $this->artisan('procurement:run')->assertSuccessful();

        $so->refresh();
        $this->assertSame('purchased', $so->status);
        $this->assertSame($key, $so->idempotency_key);
        $this->assertSame($key, MockSupplierOrder::query()->sole()->idempotency_key);
    }

    public function test_worker_crash_during_submission_goes_to_reconciliation(): void
    {
        [, $so] = $this->paidOrderWithMock(['purchase_mode' => 'transient']);
        SupplierOrder::query()->whereKey($so->id)->update(['status' => 'submitting']);
        ProcurementTask::query()->whereKey($so->task->id)->update(['status' => 'in_progress', 'locked_at' => now()->subMinutes(30)]);

        $this->artisan('procurement:run')->assertSuccessful();

        $this->assertSame('reconciling', $so->task->refresh()->status);
    }

    public function test_manual_suppliers_wait_for_an_operator(): void
    {
        $supplier = $this->supplier(['AE'], ['connector_type' => 'manual']);
        ['variant' => $v] = $this->sellableProduct(['AE'], ['supplier' => $supplier]);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $this->payOrder($order, $auth);
        $so = $this->supplierOrders($order)->first();
        $this->assertSame('manual', $so->status);

        $staff = $this->asStaff(['operations']);
        $this->postJson("/api/v1/admin/procurement/{$so->task->id}/approve", ['external_order_id' => 'WEB-7781', 'actual_cost' => '24.50'], $staff + ['Idempotency-Key' => 'approve-manual-000001'])
            ->assertOk()->assertJsonPath('data.supplier_order.status', 'purchased');

        $this->assertSame('processing', $order->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'procurement.manual_purchase_recorded']);
    }

    public function test_operator_can_retry_an_exception(): void
    {
        [, $so, $fx] = $this->paidOrderWithMock(['purchase_mode' => 'reject']);
        $this->setMock($fx['supplier'], ['purchase_mode' => 'accept']);

        $this->postJson("/api/v1/admin/procurement/{$so->task->id}/retry", [], $this->asStaff(['operations']) + ['Idempotency-Key' => 'retry-exception-00001'])->assertOk();

        $this->assertSame('purchased', $so->refresh()->status);
    }

    public function test_operator_can_switch_to_another_authorized_source(): void
    {
        [$order, $so, $fx] = $this->paidOrderWithMock(['purchase_mode' => 'reject']);
        $alt = $this->supplier(['AE']);
        $altSp = $alt->products()->create(['product_id' => $fx['product']->id, 'external_product_id' => 'ALT-1', 'cost_price' => '25.00', 'cost_currency' => 'USD', 'stock_status' => 'in_stock']);
        $altSp->mappings()->create(['variant_id' => $fx['variant']->id, 'external_variant_id' => 'ALT-1-M']);

        $this->postJson("/api/v1/admin/procurement/{$so->task->id}/switch-source", ['supplier_product_id' => $altSp->id], $this->asStaff(['operations']) + ['Idempotency-Key' => 'switch-source-000001'])
            ->assertOk()->assertJsonPath('data.supplier_order.status', 'purchased');

        $this->assertSame('cancelled', $so->refresh()->status);
        $this->assertSame('processing', $order->refresh()->status);
        $this->assertSame(1, MockSupplierOrder::query()->where('supplier_id', $alt->id)->count());
    }

    public function test_operator_cancel_with_refund_returns_the_money(): void
    {
        [$order, $so] = $this->paidOrderWithMock(['purchase_mode' => 'reject']);

        $this->postJson("/api/v1/admin/procurement/{$so->task->id}/cancel", ['refund' => true], $this->asStaff(['operations']) + ['Idempotency-Key' => 'cancel-refund-0000001'])
            ->assertOk();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('full', $order->refund_state);
        $this->assertSame($order->paid_total, $order->refunded_total);
        $this->assertDatabaseHas('refunds', ['order_id' => $order->id, 'status' => 'succeeded', 'reason_code' => 'procurement_failed']);
    }

    public function test_tracking_updates_shipments_and_order_status(): void
    {
        [$order, $so, $fx, $auth] = $this->paidOrderWithMock([]);
        /** @var MockSupplierConnector $mock */
        $mock = app(ConnectorResolver::class)->forSupplier($fx['supplier']->id);

        $mock->advance($so->external_order_id, 'shipped');
        $this->artisan('procurement:track')->assertSuccessful();
        $this->assertSame('shipped', $order->refresh()->status);

        $this->getJson("/api/v1/orders/{$order->id}/tracking", $auth)->assertOk()
            ->assertJsonPath('data.shipments.0.carrier', 'MockExpress')
            ->assertJsonPath('data.shipments.0.status', 'in_transit');

        $this->travel(25)->minutes();
        $mock->advance($so->external_order_id, 'delivered');
        $this->artisan('procurement:track')->assertSuccessful();
        $this->assertSame('delivered', $order->refresh()->status);
        $this->getJson("/api/v1/orders/{$order->id}", $auth)->assertJsonPath('data.packages.0.status', 'delivered');
    }

    public function test_customer_can_cancel_before_purchase_but_not_after(): void
    {
        [$order, , , $auth] = $this->paidOrderWithMock(['purchase_mode' => 'transient']);
        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], $auth)->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.refund_state', 'full');

        $this->resetAuth();
        [$purchased, , , $auth2] = $this->paidOrderWithMock([]);
        $this->assertErrorCode($this->postJson("/api/v1/orders/{$purchased->id}/cancel", [], $auth2), 409, 'CANCELLATION_NOT_POSSIBLE');
    }

    public function test_procurement_actions_require_permission(): void
    {
        [, $so] = $this->paidOrderWithMock(['purchase_mode' => 'reject']);

        $this->getJson('/api/v1/admin/procurement?status=exception', $this->asStaff(['finance']))->assertOk()->assertJsonPath('meta.counts.exception', 1);
        $this->resetAuth();
        $this->assertErrorCode($this->postJson("/api/v1/admin/procurement/{$so->task->id}/retry", [], $this->asStaff(['finance']) + ['Idempotency-Key' => 'retry-forbidden-0001']), 403, 'FORBIDDEN');
    }
}
