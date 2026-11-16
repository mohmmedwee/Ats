<?php

namespace Tests\Feature\Promotions;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Modules\Promotions\Infrastructure\Models\Offer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class OfferTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_buy_two_get_one_free_discounts_the_cheapest_unit_and_stacks_with_a_coupon(): void
    {
        ['product' => $pricey, 'variant' => $a] = $this->sellableProduct(['AE'], ['cost' => '30.00']);
        ['variant' => $b] = $this->sellableProduct(['AE'], ['cost' => '10.00']);
        $staff = $this->asStaff(['finance']);
        $this->postJson('/api/v1/admin/offers', ['name' => ['en' => 'Buy 2 get 1 free', 'ar' => 'اشترِ 2 والثالثة مجاناً'], 'buy_qty' => 2, 'get_qty' => 1, 'get_percent' => 100, 'scope' => 'all'], $staff)->assertCreated();
        Offer::query()->create(['name' => ['en' => 'Small'], 'buy_qty' => 1, 'get_qty' => 1, 'get_percent' => 5, 'scope' => 'all']); // worse: ignored
        $this->resetAuth();

        $this->getJson("/api/v1/products/{$pricey->id}/offers", ['X-Country' => 'AE'])->assertOk()->assertJsonPath('data.0.buy_qty', 2);

        $auth = $this->customerAuth();
        $h = $auth + ['X-Country' => 'AE'];
        $this->postJson('/api/v1/cart/items', ['variant_id' => $a->id, 'quantity' => 2], $h)->assertCreated();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $b->id, 'quantity' => 1], $h)->assertCreated();
        $address = $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'Sara', 'phone' => '0501234567', 'address_line_1' => 'Villa 1', 'city' => 'Dubai', 'state' => 'DXB'], $h)->json('data.id');
        $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard'], $h)->assertOk()->json('data');
        $cheap = collect($q['items'])->firstWhere('variant_id', $b->id)['unit_price']['amount'];
        $this->assertSame('Buy 2 get 1 free', $q['offer']['name']);
        $this->assertSame($cheap, $q['offer']['discount']);          // the cheapest unit is the free one
        $this->assertSame($cheap, $q['discount']);

        // A coupon applies on top, to what is left after the offer.
        Coupon::query()->create(['code' => 'TEN', 'type' => 'percent', 'value' => 10, 'countries' => [], 'per_customer_limit' => 1, 'is_active' => true]);
        $q2 = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard', 'coupon_code' => 'TEN'], $h)->assertOk()->json('data');
        $expectedCoupon = round(((float) $q['subtotal'] - (float) $cheap) * 0.10, 2);
        $this->assertEqualsWithDelta((float) $cheap + $expectedCoupon, (float) $q2['discount'], 0.011);

        // The order keeps per-line discounts (VAT correct per line) and the coupon redemption records only the coupon part.
        $session = $this->postJson('/api/v1/checkout', ['address_id' => $address, 'shipping_method' => 'standard', 'coupon_code' => 'TEN'], $h)->assertCreated()->json('data.id');
        $orderId = $this->postJson('/api/v1/orders', ['checkout_session_id' => $session], $h + ['Idempotency-Key' => 'order-'.Str::random(20)])->assertCreated()->json('data.id');
        $order = Order::query()->with('items')->findOrFail($orderId);
        $this->assertEqualsWithDelta((float) $q2['discount'], (float) $order->items->sum('line_discount'), 0.001);
        $this->assertEqualsWithDelta($expectedCoupon, (float) DB::table('coupon_redemptions')->where('order_id', $orderId)->value('amount'), 0.011);
    }

    public function test_offer_scope_needs_enough_eligible_units(): void
    {
        ['product' => $p, 'variant' => $v] = $this->sellableProduct(['AE']);
        ['variant' => $other] = $this->sellableProduct(['AE']);
        Offer::query()->create(['name' => ['en' => '2nd half price'], 'buy_qty' => 1, 'get_qty' => 1, 'get_percent' => 50, 'scope' => 'product', 'product_id' => $p->id]);
        $h = $this->customerAuth() + ['X-Country' => 'AE'];
        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 1], $h)->assertCreated();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $other->id, 'quantity' => 1], $h)->assertCreated();
        $address = $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'Sara', 'phone' => '0501234567', 'address_line_1' => 'Villa 1', 'city' => 'Dubai', 'state' => 'DXB'], $h)->json('data.id');
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard'], $h)->assertOk()->assertJsonPath('data.offer', null);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 1], $h);
        $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard'], $h)->assertOk()->json('data');
        $unit = (float) collect($q['items'])->firstWhere('variant_id', $v->id)['unit_price']['amount'];
        $this->assertEqualsWithDelta($unit / 2, (float) $q['offer']['discount'], 0.006);
    }
}
