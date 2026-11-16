<?php

namespace Tests\Feature\Orders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class GiftCardTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_gift_card_pays_part_then_card_pays_rest_and_refund_goes_back_to_the_card(): void
    {
        $staff = $this->asStaff(['finance']);
        $codes = $this->postJson('/api/v1/admin/gift-cards', ['amount' => 50, 'currency' => 'AED', 'quantity' => 2, 'note' => 'Influencer'], $staff)->assertCreated()->json('data.codes');
        $this->assertCount(2, $codes);
        $this->postJson('/api/v1/admin/gift-cards', ['amount' => 50, 'currency' => 'SAR'], $staff)->assertCreated();
        $sar = DB::table('gift_cards')->where('currency', 'SAR')->value('code');
        $this->resetAuth();

        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $this->getJson("/api/v1/gift-cards/{$codes[0]}", $auth)->assertOk()->assertJsonPath('data.balance.amount', '50.00');
        $order = $this->placeOrder([[$v, 1]], $auth);
        $this->assertGreaterThan(50, (float) $order->grand_total);

        $pay = fn (string $code, string $key) => $this->postJson('/api/v1/payments/gift-card', ['order_id' => $order->id, 'code' => $code], $auth + ['Idempotency-Key' => $key]);
        $pay($sar, 'gc-'.Str::random(20))->assertUnprocessable()->assertJsonPath('error.details.reason', 'currency');
        $pay('GC-NOPE-NOPE-NOPE', 'gc-'.Str::random(20))->assertUnprocessable()->assertJsonPath('error.details.reason', 'not_found');
        $key = 'gc-'.Str::random(20);
        $pay($codes[0], $key)->assertCreated()->assertJsonPath('data.amount.amount', '50.00');
        $pay($codes[0], $key)->assertCreated(); // same key: same payment, card not charged twice
        $this->assertSame('0.00', (string) DB::table('gift_cards')->where('code', $codes[0])->value('balance'));
        $this->assertSame('pending_payment', $order->refresh()->status);
        $this->assertSame('50.00', (string) $order->paid_total);

        // The rest by card: the intent asks only for what is left.
        $payment = $this->createIntent($order, $auth);
        $this->assertSame(number_format((float) $order->grand_total - 50, 2, '.', ''), (string) $payment->amount);
        $this->capture($payment)->assertOk();
        $this->assertNotNull($order->refresh()->paid_at);
        $this->assertSame((string) $order->grand_total, (string) $order->paid_total);

        // Empty card now.
        $this->getJson("/api/v1/gift-cards/{$codes[0]}", $auth)->assertOk()->assertJsonPath('data.balance.amount', '0.00');

        // A refund goes back to the gift card first (the oldest payment).
        $this->resetAuth();
        $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '20.00', 'reason_code' => 'goodwill'], $this->asStaff(['finance']) + ['Idempotency-Key' => 'rf-'.Str::random(20)])->assertCreated();
        $this->assertSame('20.00', (string) DB::table('gift_cards')->where('code', $codes[0])->value('balance'));

        // Switched-off cards are invalid.
        $this->resetAuth();
        $id = DB::table('gift_cards')->where('code', $codes[1])->value('id');
        $this->patchJson("/api/v1/admin/gift-cards/{$id}", ['is_active' => false], $this->asStaff(['finance']))->assertOk();
        $this->resetAuth();
        $this->getJson("/api/v1/gift-cards/{$codes[1]}", $this->customerAuth())->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'gift_card.issued']);
    }
}
