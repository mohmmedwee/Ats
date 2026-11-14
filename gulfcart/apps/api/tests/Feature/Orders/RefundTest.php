<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Models\Refund;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class RefundTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    private function paidOrder(string $cost = '24.00', int $qty = 1): Order
    {
        ['variant' => $v] = $this->sellableProduct(['AE'], ['cost' => $cost]);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, $qty]], $auth);
        $this->payOrder($order, $auth);
        $this->resetAuth();

        return $order->refresh();
    }

    public function test_partial_refund_by_an_approver_is_executed_immediately(): void
    {
        $order = $this->paidOrder();

        $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '20.00', 'reason_code' => 'goodwill'], $this->asStaff(['finance']) + ['Idempotency-Key' => 'refund-partial-00001'])
            ->assertCreated()->assertJsonPath('data.0.status', 'succeeded');

        $order->refresh();
        $this->assertSame('20.00', $order->refunded_total);
        $this->assertSame('partial', $order->refund_state);
        $this->assertSame('20.00', Payment::query()->sole()->refunded_amount);
    }

    public function test_refunds_never_exceed_the_captured_amount(): void
    {
        $order = $this->paidOrder();
        $h = $this->asStaff(['finance']);

        $this->assertErrorCode($this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => number_format((float) $order->paid_total + 0.01, 2, '.', ''), 'reason_code' => 'goodwill'], $h + ['Idempotency-Key' => 'refund-over-000000001']), 422, 'REFUND_EXCEEDS_CAPTURED');

        $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => $order->paid_total, 'reason_code' => 'goodwill'], $h + ['Idempotency-Key' => 'refund-full-000000001'])->assertCreated();
        $this->assertErrorCode($this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '1.00', 'reason_code' => 'goodwill'], $h + ['Idempotency-Key' => 'refund-extra-00000001']), 422, 'REFUND_EXCEEDS_CAPTURED');
        $this->assertSame('full', $order->refresh()->refund_state);
    }

    public function test_pending_refunds_count_against_the_available_amount(): void
    {
        $order = $this->paidOrder('400.00');
        $support = $this->asStaff(['support']); // can request, cannot approve

        $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => $order->paid_total, 'reason_code' => 'customer_request'], $support + ['Idempotency-Key' => 'refund-pending-000001'])
            ->assertCreated()->assertJsonPath('data.0.status', 'requested');
        $this->assertErrorCode($this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '1.00', 'reason_code' => 'customer_request'], $support + ['Idempotency-Key' => 'refund-pending-000002']), 422, 'REFUND_EXCEEDS_CAPTURED');
    }

    public function test_large_refunds_need_a_second_approver(): void
    {
        $order = $this->paidOrder('400.00');
        $requester = $this->staff(['finance']);
        $reqHeaders = ['Authorization' => 'Bearer '.$requester->createToken('t')->plainTextToken];

        $id = $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '600.00', 'reason_code' => 'damaged'], $reqHeaders + ['Idempotency-Key' => 'refund-large-00000001'])
            ->assertCreated()->assertJsonPath('data.0.status', 'requested')->json('data.0.id');

        $this->assertErrorCode($this->postJson("/api/v1/admin/refunds/{$id}/approve", [], $reqHeaders), 403, 'FOUR_EYES_REQUIRED');
        $this->resetAuth();
        $this->postJson("/api/v1/admin/refunds/{$id}/approve", [], $this->asStaff(['finance']))->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('600.00', $order->refresh()->refunded_total);
    }

    public function test_requests_can_be_rejected(): void
    {
        $order = $this->paidOrder();
        $id = $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '5.00', 'reason_code' => 'goodwill'], $this->asStaff(['support']) + ['Idempotency-Key' => 'refund-reject-0000001'])->json('data.0.id');
        $this->resetAuth();

        $this->postJson("/api/v1/admin/refunds/{$id}/reject", ['reason' => 'Outside policy'], $this->asStaff(['finance']))->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('0.00', $order->refresh()->refunded_total);
        $this->assertSame('rejected', Refund::query()->sole()->status);
    }

    public function test_refund_request_permission_is_enforced(): void
    {
        $order = $this->paidOrder();

        $this->assertErrorCode($this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '5.00', 'reason_code' => 'goodwill'], $this->asStaff(['catalog_manager']) + ['Idempotency-Key' => 'refund-perm-000000001']), 403, 'FORBIDDEN');
    }
}
