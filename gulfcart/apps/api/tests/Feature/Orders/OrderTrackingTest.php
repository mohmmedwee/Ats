<?php

namespace Tests\Feature\Orders;

use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class OrderTrackingTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_timeline_steps_estimate_and_tracking_by_order_number_and_phone(): void
    {
        ['variant' => $variant] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$variant, 1]], $auth);

        $package = $this->getJson("/api/v1/orders/{$order->id}", $auth)->assertOk()->json('data.packages.0');
        $this->assertSame(['placed', 'confirmed', 'preparing', 'shipped', 'delivered'], array_column($package['steps'], 'key'));
        $this->assertSame([true, false, false, false, false], array_column($package['steps'], 'done'));
        $this->assertNotNull($package['estimated_delivery']);
        $this->assertLessThanOrEqual($package['estimated_delivery']['to'], $package['estimated_delivery']['from']);

        $this->payOrder($order, $auth);
        $package = $this->getJson("/api/v1/orders/{$order->id}", $auth)->assertOk()->json('data.packages.0');
        $this->assertTrue($package['steps'][1]['done']);
        $this->assertNotNull($package['steps'][1]['at']);

        // Public tracking: order number + phone on the address (any format), progress only.
        $this->resetAuth();
        $res = $this->postJson('/api/v1/orders/track', ['order_number' => strtolower($order->order_number), 'phone' => '+971 50 123 4567'])->assertOk();
        $res->assertJsonPath('data.order_number', $order->order_number)->assertJsonPath('data.packages.0.steps.1.done', true);
        $this->assertArrayNotHasKey('unit_price', $res->json('data.packages.0.items.0'));
        $this->assertArrayNotHasKey('shipping_address', $res->json('data'));
        $this->assertArrayNotHasKey('totals', $res->json('data'));

        $this->assertErrorCode($this->postJson('/api/v1/orders/track', ['order_number' => $order->order_number, 'phone' => '0509999999']), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->postJson('/api/v1/orders/track', ['order_number' => 'GC-0', 'phone' => '0501234567']), 404, 'NOT_FOUND');
    }

    public function test_address_map_pin_is_validated_and_kept_on_the_order(): void
    {
        ['variant' => $variant] = $this->sellableProduct(['AE']);
        $h = $this->customerAuth() + ['X-Country' => 'AE'];
        $base = ['country' => 'AE', 'full_name' => 'Sara', 'phone' => '0501234567', 'address_line_1' => 'Villa 1', 'city' => 'Dubai', 'state' => 'DXB'];
        $this->postJson('/api/v1/addresses', $base + ['latitude' => 95, 'longitude' => 55.27], $h)->assertUnprocessable();
        $this->postJson('/api/v1/addresses', $base + ['latitude' => 25.2048], $h)->assertUnprocessable();
        $id = $this->postJson('/api/v1/addresses', $base + ['latitude' => 25.204849, 'longitude' => 55.270783], $h)->assertCreated()
            ->assertJsonPath('data.latitude', 25.204849)->json('data.id');

        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1], $h)->assertCreated();
        $session = $this->postJson('/api/v1/checkout', ['address_id' => $id, 'shipping_method' => 'standard'], $h)->assertCreated()->json('data.id');
        $orderId = $this->postJson('/api/v1/orders', ['checkout_session_id' => $session], $h + ['Idempotency-Key' => 'order-pin-'.str_repeat('x', 12)])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/orders/{$orderId}", $h)->assertJsonPath('data.shipping_address.latitude', '25.204849')->assertJsonPath('data.shipping_address.longitude', '55.270783');
    }
}
