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
}
