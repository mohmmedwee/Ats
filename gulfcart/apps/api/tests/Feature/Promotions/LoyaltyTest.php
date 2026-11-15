<?php

namespace Tests\Feature\Promotions;

use App\Modules\Promotions\Infrastructure\Models\LoyaltyAccount;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class LoyaltyTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    /** @param array<string, string> $auth */
    private function points(array $auth): array
    {
        $this->resetAuth();

        return $this->getJson('/api/v1/loyalty', $auth + ['X-Country' => 'AE'])->assertOk()->json('data');
    }

    public function test_paid_orders_earn_points_refunds_take_them_back_and_points_become_a_personal_code(): void
    {
        ['variant' => $variant] = $this->sellableProduct(['AE']);
        $user = $this->customer();
        $auth = $this->customerAuth($user);
        $this->assertSame(0, $this->points($auth)['balance']);

        $order = $this->placeOrder([[$variant, 3]], $auth);
        $this->payOrder($order, $auth);
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $order->refresh();
        $expected = (int) floor((float) $order->grand_total - (float) $order->shipping_total - (float) $order->customs_total);
        $data = $this->points($auth);
        $this->assertSame($expected, $data['balance']);
        $this->assertSame('earn', $data['history'][0]['type']);

        // Re-delivered events never double-count.
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->assertSame($expected, $this->points($auth)['balance']);

        // Half refunded: half the points go.
        $half = number_format((float) $order->grand_total / 2, 2, '.', '');
        $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => $half, 'reason_code' => 'goodwill'], $this->asStaff(['finance']) + ['Idempotency-Key' => 'refund-'.Str::random(16)])->assertCreated();
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $balance = $this->points($auth)['balance'];
        $this->assertEqualsWithDelta($expected / 2, $balance, 1);

        // Redeem 100 points → 5.00 AED personal code, usable only by this customer.
        $this->resetAuth();
        $this->assertErrorCode($this->postJson('/api/v1/loyalty/redeem', ['points' => 150], $auth + ['X-Country' => 'AE']), 422, 'LOYALTY_INVALID_POINTS');
        $this->assertErrorCode($this->postJson('/api/v1/loyalty/redeem', ['points' => 1000000], $auth + ['X-Country' => 'AE']), 422, 'LOYALTY_INSUFFICIENT');
        $code = $this->postJson('/api/v1/loyalty/redeem', ['points' => 100], $auth + ['X-Country' => 'AE'])->assertCreated()
            ->assertJsonPath('data.value.amount', '5.00')->json('data.code');
        $this->assertSame($balance - 100, $this->points($auth)['balance']);
        $this->assertSame($code, $this->points($auth)['codes'][0]['code']);

        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1], $auth + ['X-Country' => 'AE'])->assertCreated();
        $address = $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'Sara', 'phone' => '0501234567', 'address_line_1' => 'Villa 1', 'city' => 'Dubai', 'state' => 'DXB'], $auth + ['X-Country' => 'AE'])->json('data.id');
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard', 'coupon_code' => $code], $auth + ['X-Country' => 'AE'])
            ->assertOk()->assertJsonPath('data.discount', '5.00');

        $this->resetAuth();
        $stranger = $this->customerAuth() + ['X-Country' => 'AE'];
        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1], $stranger)->assertCreated();
        $a2 = $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'Ali', 'phone' => '0501234568', 'address_line_1' => 'Villa 2', 'city' => 'Dubai', 'state' => 'DXB'], $stranger)->json('data.id');
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $a2, 'shipping_method' => 'standard', 'coupon_code' => $code], $stranger)
            ->assertUnprocessable()->assertJsonPath('error.details.reason', 'not_found');

        // Personal codes stay out of the staff coupon list.
        $this->resetAuth();
        $this->assertNotContains($code, collect($this->getJson('/api/v1/admin/coupons', $this->asStaff(['finance']))->json('data'))->pluck('code'));
    }

    public function test_referral_gives_the_friend_a_welcome_code_and_the_referrer_bonus_after_the_first_paid_order(): void
    {
        ['variant' => $variant] = $this->sellableProduct(['AE']);
        $referrer = $this->customer();
        $refAuth = $this->customerAuth($referrer);
        $code = $this->points($refAuth)['referral']['code'];

        $friend = $this->customer();
        $friendAuth = $this->customerAuth($friend);
        $this->resetAuth();
        $this->assertErrorCode($this->postJson('/api/v1/loyalty/referral', ['code' => 'NOPE99'], $friendAuth + ['X-Country' => 'AE']), 422, 'REFERRAL_INVALID');
        $this->resetAuth();
        $this->postJson('/api/v1/loyalty/referral', ['code' => $code], $refAuth + ['X-Country' => 'AE'])->assertUnprocessable()->assertJsonPath('error.details.reason', 'own_code');
        $this->resetAuth();

        $welcome = $this->postJson('/api/v1/loyalty/referral', ['code' => strtolower($code)], $friendAuth + ['X-Country' => 'AE'])->assertCreated()
            ->assertJsonPath('data.percent', '10.00')->json('data.code');
        $this->assertStringStartsWith('HI-', $welcome);
        $this->postJson('/api/v1/loyalty/referral', ['code' => $code], $friendAuth + ['X-Country' => 'AE'])->assertUnprocessable()->assertJsonPath('error.details.reason', 'already_used');
        $this->assertFalse($this->points($friendAuth)['referral']['can_enter_code']);

        $this->resetAuth();
        $order = $this->placeOrder([[$variant, 1]], $friendAuth);
        $this->payOrder($order, $friendAuth);
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->assertSame(500, $this->points($refAuth)['balance']);
        $this->assertSame(1, $this->points($refAuth)['referral']['friends_joined']);

        // Only the first order rewards the referrer.
        $this->resetAuth();
        $order2 = $this->placeOrder([[$variant, 1]], $friendAuth);
        $this->payOrder($order2, $friendAuth);
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->assertSame(500, $this->points($refAuth)['balance']);

        // Staff summary and manual adjustment.
        $this->resetAuth();
        $staff = $this->asStaff(['finance']);
        $this->postJson('/api/v1/admin/loyalty/adjust', ['user_id' => $referrer->id, 'points' => -50, 'note' => 'Correction'], $staff)->assertOk()->assertJsonPath('data.balance', 450);
        $summary = $this->getJson('/api/v1/admin/loyalty', $staff)->assertOk()->json('data');
        $this->assertSame(1, $summary['referrals']);
        $this->assertSame((int) LoyaltyAccount::query()->sum('balance'), $summary['outstanding_points']);
    }
}
