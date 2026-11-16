<?php

namespace Tests\Feature\Orders;

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Invoices\Infrastructure\Models\Invoice;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class CashOnDeliveryTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    /** @var \ArrayObject<int, array{0: string, 1: string}> */
    private \ArrayObject $sms;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gulfcart.cod.enabled' => true, 'gulfcart.cod.countries' => 'AE', 'gulfcart.cod.fee.AE' => '10', 'gulfcart.cod.max_amount.AE' => '1000', 'gulfcart.cod.require_otp' => true, 'gulfcart.cod.block_after_refusals' => 1]);
        $this->sms = new \ArrayObject;
        $log = $this->sms;
        $this->app->instance(SmsSender::class, new class($log) implements SmsSender
        {
            /** @param \ArrayObject<int, array{0: string, 1: string}> $log */
            public function __construct(private readonly \ArrayObject $log) {}

            public function send(string $e164Phone, string $message): void
            {
                $this->log->append([$e164Phone, $message]);
            }
        });
    }

    /**
     * @param  array<string, string>  $auth
     * @return array{0: Order, 1: array<string, string>}
     */
    private function codOrder(array $auth, mixed $variant): array
    {
        $h = $auth + ['X-Country' => 'AE'];
        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1], $h)->assertCreated();
        $address = $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'Sara', 'phone' => '050 123 4567', 'address_line_1' => 'Villa 1', 'city' => 'Dubai', 'state' => 'DXB'], $h)->json('data.id');
        $online = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard'], $h)->assertOk()->json('data');
        $cod = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address, 'shipping_method' => 'standard', 'payment_method' => 'cod'], $h)->assertOk()->json('data');
        $this->assertSame('10.00', $cod['cod_fee']);
        $this->assertEqualsWithDelta((float) $online['total'] + 10, (float) $cod['total'], 0.001);

        $session = $this->postJson('/api/v1/checkout', ['address_id' => $address, 'shipping_method' => 'standard', 'payment_method' => 'cod'], $h)->assertCreated()->json('data.id');
        $orderId = $this->postJson('/api/v1/orders', ['checkout_session_id' => $session], $h + ['Idempotency-Key' => 'order-'.Str::random(20)])->assertCreated()->json('data.id');

        return [Order::query()->findOrFail($orderId), $h];
    }

    public function test_confirm_by_sms_fulfil_then_cash_collected(): void
    {
        ['variant' => $variant] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $cod = collect($this->getJson('/api/v1/payments/methods', $auth + ['X-Country' => 'AE'])->assertOk()->json('data'))->firstWhere('provider', 'cod');
        $this->assertTrue($cod['available']);
        $this->assertSame('10.00', $cod['fee']['amount']);

        [$order, $h] = $this->codOrder($auth, $variant);
        $this->assertSame('cod', $order->payment_method);
        $payment = $this->postJson('/api/v1/payments/intent', ['order_id' => $order->id, 'provider' => 'cod'], $h + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertCreated()->assertJsonPath('data.status', 'requires_action')->assertJsonPath('data.redirect_url', null)->json('data.id');
        $this->assertCount(1, $this->sms);
        $this->assertSame('+971501234567', $this->sms[0][0]);
        preg_match('/(\d{6})/', $this->sms[0][1], $m);

        $this->assertErrorCode($this->postJson("/api/v1/payments/{$payment}/cod-confirm", ['code' => '000000'], $h), 422, 'COD_CODE_INVALID');
        $this->postJson("/api/v1/payments/{$payment}/cod-confirm", ['code' => $m[1]], $h)->assertOk()->assertJsonPath('data.status', 'pending_collection');
        // Confirmed, and fulfilment may already have started (the supplier purchase runs right after).
        $this->assertContains($order->refresh()->status, ['cod_confirmed', 'processing']);
        $this->assertSame('cod', $order->payment_method);
        $this->getJson("/api/v1/orders/{$order->id}", $h)->assertJsonPath('data.payment_method', 'cod')->assertJsonPath('data.packages.0.steps.1.done', true);

        // Fulfilment starts without payment; no invoice yet.
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->assertGreaterThan(0, $this->supplierOrders($order)->count());
        $this->assertSame(0, Invoice::query()->where('order_id', $order->id)->count());

        $this->resetAuth();
        $this->postJson("/api/v1/admin/orders/{$order->id}/cod/collected", [], $this->asStaff(['finance']))->assertOk()->assertJsonPath('data.payment_status', 'captured');
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertSame((string) $order->grand_total, (string) $order->paid_total);
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->where('type', 'invoice')->count());
        $this->resetAuth();
        $this->postJson("/api/v1/admin/orders/{$order->id}/cod/collected", [], $this->asStaff(['finance']))->assertStatus(409);
    }

    public function test_refused_parcel_cancels_and_blocks_cod_and_limits_apply(): void
    {
        ['variant' => $variant] = $this->sellableProduct(['AE']);
        config(['gulfcart.cod.require_otp' => false]);
        $auth = $this->customerAuth();
        [$order, $h] = $this->codOrder($auth, $variant);
        $this->postJson('/api/v1/payments/intent', ['order_id' => $order->id, 'provider' => 'cod'], $h + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertCreated()->assertJsonPath('data.status', 'pending_collection');
        $this->assertCount(0, $this->sms);

        $this->resetAuth();
        $this->postJson("/api/v1/admin/orders/{$order->id}/cod/refused", ['note' => 'Refused at the door'], $this->asStaff(['finance']))->assertOk();
        $this->assertSame('cancelled', $order->refresh()->status);

        $this->resetAuth();
        $cod = collect($this->getJson('/api/v1/payments/methods', $auth + ['X-Country' => 'AE'])->json('data'))->firstWhere('provider', 'cod');
        $this->assertFalse($cod['available']);
        $this->assertSame('refused_before', $cod['reason']);

        // Over the limit: cash on delivery cannot even be quoted.
        config(['gulfcart.cod.max_amount.AE' => '50']);
        $this->resetAuth();
        $other = $this->customerAuth() + ['X-Country' => 'AE'];
        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2], $other)->assertCreated();
        $a = $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'Ali', 'phone' => '0501234568', 'address_line_1' => 'Villa 2', 'city' => 'Dubai', 'state' => 'DXB'], $other)->json('data.id');
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $a, 'shipping_method' => 'standard', 'payment_method' => 'cod'], $other)
            ->assertUnprocessable()->assertJsonPath('error.code', 'COD_NOT_AVAILABLE')->assertJsonPath('error.details.reason', 'max_amount');

        // An order checked out for online payment cannot switch to cash on delivery (the fee is missing).
        config(['gulfcart.cod.max_amount.AE' => '1000']);
        $this->resetAuth();
        $buyer = $this->customer();
        $buyerAuth = $this->customerAuth($buyer);
        $online = $this->placeOrder([[$variant, 1]], $buyerAuth);
        $this->postJson('/api/v1/payments/intent', ['order_id' => $online->id, 'provider' => 'cod'], $buyerAuth + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertUnprocessable()->assertJsonPath('error.details.reason', 'not_quoted');
        $this->assertInstanceOf(User::class, $buyer);
    }
}
