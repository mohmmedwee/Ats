<?php

namespace Tests\Feature\Promotions;

use App\Modules\Invoices\Infrastructure\Models\Invoice;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Modules\Promotions\Infrastructure\Models\CouponRedemption;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class CouponTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    /** @return array{0: array<string, string>, 1: string} headers and address ID */
    private function cartWith(array $lines, array $auth): array
    {
        $h = $auth + ['X-Country' => 'AE'];
        foreach ($lines as [$variant, $qty]) {
            $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => $qty], $h)->assertCreated();
        }
        $address = $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'Sara', 'phone' => '0501234567', 'address_line_1' => 'Villa 1', 'city' => 'Dubai', 'state' => 'DXB'], $h)->assertCreated()->json('data.id');

        return [$h, $address];
    }

    private function coupon(array $attrs): Coupon
    {
        return Coupon::query()->create($attrs + ['description' => null, 'countries' => [], 'per_customer_limit' => 1, 'is_active' => true]);
    }

    public function test_admin_creates_a_percent_coupon_and_the_order_and_invoice_carry_the_discounted_vat(): void
    {
        $this->postJson('/api/v1/admin/coupons', ['code' => 'welcome10', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 50], $this->asStaff(['finance']))
            ->assertCreated()->assertJsonPath('data.code', 'WELCOME10');

        ['variant' => $a] = $this->sellableProduct(['AE']);
        ['variant' => $b] = $this->sellableProduct(['AE'], ['cost' => '13.00']);
        $auth = $this->customerAuth();
        [$h, $address] = $this->cartWith([[$a, 2], [$b, 1]], $auth);

        $plain = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard'], $h)->assertOk()->json('data');
        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard', 'coupon_code' => ' Welcome10 '], $h)
            ->assertOk()->assertJsonPath('data.coupon.code', 'WELCOME10')->json('data');
        $expected = number_format(round((float) $plain['subtotal'] * 0.10, 2), 2, '.', '');
        $this->assertSame($expected, $quote['discount']);
        $this->assertEqualsWithDelta((float) $plain['total'] - (float) $expected, (float) $quote['total'], 0.001);
        $this->assertLessThan((float) $plain['tax'], (float) $quote['tax']);

        $session = $this->postJson('/api/v1/checkout', ['address_id' => $address, 'shipping_method' => 'standard', 'coupon_code' => 'WELCOME10'], $h)->assertCreated()->json('data.id');
        $orderId = $this->postJson('/api/v1/orders', ['checkout_session_id' => $session], $h + ['Idempotency-Key' => 'order-'.Str::random(20)])->assertCreated()->json('data.id');
        $order = Order::query()->with('items')->findOrFail($orderId);
        $this->assertSame('WELCOME10', $order->coupon_code);
        $this->assertSame($expected, (string) $order->discount_total);
        $this->assertSame($expected, number_format((float) $order->items->sum('line_discount'), 2, '.', ''));
        $this->assertSame(1, CouponRedemption::query()->where('order_id', $orderId)->where('status', 'active')->count());

        $this->payOrder($order, $auth);
        $invoice = Invoice::query()->where('order_id', $orderId)->where('type', 'invoice')->sole();
        $this->assertSame((string) $order->refresh()->grand_total, (string) $invoice->gross_total);
        $this->assertSame((string) $order->tax_total, (string) $invoice->vat_total);

        // Used once per customer
        [$h2, $address2] = [$h, $address];
        $this->postJson('/api/v1/cart/items', ['variant_id' => $a->id, 'quantity' => 2], $h2)->assertCreated();
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address2, 'shipping_method' => 'standard', 'coupon_code' => 'WELCOME10'], $h2)
            ->assertUnprocessable()->assertJsonPath('error.code', 'COUPON_INVALID')->assertJsonPath('error.details.reason', 'already_used');
    }

    public function test_conditions_free_shipping_and_release_on_expiry(): void
    {
        $this->coupon(['code' => 'SAONLY', 'type' => 'fixed', 'value' => 20, 'countries' => ['SA']]);
        $this->coupon(['code' => 'OLD', 'type' => 'percent', 'value' => 5, 'ends_at' => now()->subDay()]);
        $this->coupon(['code' => 'BIG', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 100000]);
        $this->coupon(['code' => 'SHIPFREE', 'type' => 'free_shipping', 'value' => 0, 'usage_limit' => 1]);

        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        [$h, $address] = $this->cartWith([[$v, 1]], $auth);
        $q = fn (string $code) => $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard', 'coupon_code' => $code], $h);

        $q('NOPE')->assertJsonPath('error.details.reason', 'not_found');
        $q('SAONLY')->assertJsonPath('error.details.reason', 'country');
        $q('OLD')->assertJsonPath('error.details.reason', 'expired');
        $q('BIG')->assertJsonPath('error.details.reason', 'min_subtotal');
        $free = $q('SHIPFREE')->assertOk()->json('data');
        $this->assertSame('0.00', $free['shipping']);
        $this->assertTrue($free['shipping_method']['free']);

        $session = $this->postJson('/api/v1/checkout', ['address_id' => $address, 'shipping_method' => 'standard', 'coupon_code' => 'SHIPFREE'], $h)->assertCreated()->json('data.id');
        $orderId = $this->postJson('/api/v1/orders', ['checkout_session_id' => $session], $h + ['Idempotency-Key' => 'order-'.Str::random(20)])->assertCreated()->json('data.id');

        // Limit 1 reached for everyone…
        $other = $this->customerAuth();
        [$h3, $address3] = $this->cartWith([[$v, 1]], $other);
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address3, 'shipping_method' => 'standard', 'coupon_code' => 'SHIPFREE'], $h3)->assertJsonPath('error.details.reason', 'used_up');

        // …until the unpaid order expires and gives it back.
        Outbox::record('order', $orderId, 'order.expired', ['order_id' => $orderId]);
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address3, 'shipping_method' => 'standard', 'coupon_code' => 'SHIPFREE'], $h3)->assertOk();
    }
}
