<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Database\QueryException;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class OrderAndPaymentTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_order_is_created_from_the_locked_session_with_immutable_snapshots(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $order = $this->placeOrder([[$v, 2]], $this->customerAuth());

        $this->assertMatchesRegularExpression('/^GC-\d{5,}$/', $order->order_number);
        $this->assertSame((float) $order->grand_total, (float) $order->subtotal + (float) $order->shipping_total + (float) $order->customs_total);
        $item = OrderItem::query()->where('order_id', $order->id)->sole();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('Summer Dress', $item->product_snapshot['title']['en']);
        $this->assertDatabaseHas('checkout_sessions', ['status' => 'completed']);
        $this->assertDatabaseHas('carts', ['status' => 'converted']);

        $this->expectException(QueryException::class);
        $item->forceFill(['unit_gross_price' => '1.00'])->save();
    }

    public function test_one_order_per_session_and_idempotent_creation(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);

        $again = $this->postJson('/api/v1/orders', ['checkout_session_id' => $order->checkout_session_id], $auth + ['Idempotency-Key' => 'another-key-123456789'])
            ->assertCreated()->json('data.id');
        $this->assertSame($order->id, $again);
        $this->assertDatabaseCount('orders', 1);
        $this->assertErrorCode($this->postJson('/api/v1/orders', ['checkout_session_id' => $order->checkout_session_id], $auth), 400, 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_expired_quote_cannot_become_an_order(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 1], $auth);
        $addressId = $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'A', 'phone' => '0501234567', 'address_line_1' => 'x', 'city' => 'Dubai', 'state' => 'DXB'], $auth)->json('data.id');
        $session = $this->postJson('/api/v1/checkout', ['address_id' => $addressId, 'shipping_method' => 'standard'], $auth)->json('data.id');

        $this->travel(31)->minutes();
        $this->assertErrorCode($this->postJson('/api/v1/orders', ['checkout_session_id' => $session], $auth + ['Idempotency-Key' => 'k-expired-0000001']), 410, 'QUOTE_EXPIRED');
    }

    public function test_successful_payment_marks_the_order_paid_and_starts_procurement(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);

        $payment = $this->createIntent($order, $auth);
        $this->assertSame('requires_action', $payment->status);
        $this->assertStringContainsString('/payment/return?payment=', (string) $payment->redirect_url);

        $this->postJson("/api/v1/payments/{$payment->id}/sandbox-complete", ['outcome' => 'success'], $auth)->assertOk()
            ->assertJsonPath('data.status', 'captured');

        $order->refresh();
        $this->assertSame('processing', $order->status); // paid → supplier purchase accepted (mock)
        $this->assertSame($order->grand_total, $order->paid_total);
        $this->assertCount(1, $this->supplierOrders($order));
        $this->assertSame('purchased', $this->supplierOrders($order)->first()->status);
        $this->assertDatabaseCount('mock_supplier_orders', 1);
    }

    public function test_duplicate_webhooks_capture_only_once(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $payment = $this->createIntent($order, $auth);

        $this->capture($payment, null, 'evt_same_event_001')->assertJsonPath('data.result', 'processed');
        $this->capture($payment, null, 'evt_same_event_001')->assertJsonPath('data.result', 'duplicate');
        $this->capture($payment, null, 'evt_other_event_002')->assertJsonPath('data.result', 'ignored');

        $this->assertSame($order->grand_total, $order->refresh()->paid_total);
        $this->assertDatabaseCount('mock_supplier_orders', 1);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.paid')->count());
    }

    public function test_webhook_signature_and_replay_protection(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $payment = $this->createIntent($this->placeOrder([[$v, 1]], $auth), $auth);

        $this->assertErrorCode($this->postJson('/api/v1/webhooks/payments/sandbox', ['type' => 'payment.captured']), 401, 'WEBHOOK_SIGNATURE_INVALID');
        $this->assertErrorCode($this->webhook(['type' => 'payment.captured', 'payment_reference' => $payment->provider_reference, 'amount' => $payment->amount, 'currency' => 'AED'], time() - 3600), 401, 'WEBHOOK_SIGNATURE_INVALID');
        $this->assertSame('requires_action', $payment->refresh()->status);
    }

    public function test_amount_mismatch_is_not_captured(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $payment = $this->createIntent($order, $auth);

        $this->capture($payment, '1.00')->assertJsonPath('data.result', 'failed');

        $this->assertSame('pending_payment', $order->refresh()->status);
        $this->assertDatabaseHas('payment_webhook_events', ['status' => 'failed']);
    }

    public function test_failed_payment_can_be_retried(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $first = $this->createIntent($order, $auth);

        $this->postJson("/api/v1/payments/{$first->id}/sandbox-complete", ['outcome' => 'failure'], $auth)->assertJsonPath('data.status', 'failed');
        $this->assertSame('payment_failed', $order->refresh()->status);

        $second = $this->createIntent($order, $auth);
        $this->assertNotSame($first->id, $second->id);
        $this->capture($second)->assertJsonPath('data.result', 'processed');
        $this->assertNotSame('payment_failed', $order->refresh()->status);
        $this->assertErrorCode($this->postJson('/api/v1/payments/intent', ['order_id' => $order->id], $auth + ['Idempotency-Key' => 'pay-again-000000001']), 409, 'ORDER_ALREADY_PAID');
    }

    public function test_unpaid_orders_expire_and_late_captures_are_honoured(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $payment = $this->createIntent($order, $auth);

        $this->travel(61)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('expired', $order->refresh()->status);

        $this->capture($payment)->assertJsonPath('data.result', 'processed');
        $this->assertNotSame('expired', $order->refresh()->status);
    }

    public function test_customer_views_hide_costs_and_group_packages(): void
    {
        ['variant' => $a] = $this->sellableProduct(['AE']);
        ['variant' => $b] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$a, 1], [$b, 1]], $auth);
        $this->payOrder($order, $auth);

        $json = $this->getJson("/api/v1/orders/{$order->id}", $auth)->assertOk()
            ->assertJsonCount(2, 'data.packages')
            ->assertJsonPath('data.packages.0.status', 'preparing')
            ->assertJsonPath('data.can_cancel', false)
            ->getContent();
        foreach (['supplier', 'unit_supplier_cost', 'exchange_rate', 'external_'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $json);
        }
        $this->getJson('/api/v1/orders', $auth)->assertJsonPath('data.0.order_number', $order->order_number);

        $this->resetAuth();
        $this->assertErrorCode($this->getJson("/api/v1/orders/{$order->id}", $this->customerAuth()), 404, 'NOT_FOUND');
    }
}
