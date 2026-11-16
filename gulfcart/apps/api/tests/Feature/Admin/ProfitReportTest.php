<?php

namespace Tests\Feature\Admin;

use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class ProfitReportTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_profit_by_day_and_product_matches_the_paid_order(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$variant, 2]], $auth);
        $this->payOrder($order, $auth);
        $this->placeOrder([[$variant, 1]], $auth); // unpaid: not counted
        $this->resetAuth();

        $item = $order->items()->firstOrFail();
        $revenue = (float) $item->line_total / (1 + (float) $item->vat_rate / 100);
        $cost = 2 * ((float) $item->unit_supplier_cost + (float) $item->unit_supplier_shipping) * (float) $item->exchange_rate;
        $expected = $revenue - $cost - (float) $item->line_total * (float) config('gulfcart.pricing.payment_fee_percent') / 100;

        $today = now()->toDateString();
        $data = $this->getJson("/api/v1/admin/finance/profit?from={$today}&to={$today}&country=AE", $this->asStaff(['finance']))->assertOk()->json('data');
        $this->assertSame(1, $data['totals']['orders']);
        $this->assertSame(2, $data['totals']['units']);
        $this->assertEqualsWithDelta($expected, (float) $data['totals']['profit'], 0.02);
        $this->assertSame($product->id, $data['top_products'][0]['product_id']);
        $this->assertSame(1, $data['daily'][0]['orders']);
        $this->assertSame([], $data['losing_products']);

        $this->resetAuth();
        $this->getJson("/api/v1/admin/finance/profit?from={$today}&to={$today}&country=AE", $this->asStaff(['support']))->assertForbidden();
    }
}
