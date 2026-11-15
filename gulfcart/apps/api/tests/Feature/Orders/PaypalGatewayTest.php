<?php

namespace Tests\Feature\Orders;

use App\Modules\Payments\Application\Gateways\PaypalGateway;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class PaypalGatewayTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    /** @var array<string, array<string, mixed>> PayPal orders by ID */
    private array $orders = [];

    private bool $signatureOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gulfcart.payments.methods' => 'paypal', 'gulfcart.payments.paypal.client_id' => 'cid', 'gulfcart.payments.paypal.client_secret' => 'sec', 'gulfcart.payments.paypal.webhook_id' => 'WH1']);
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            if (str_ends_with($url, '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 32400]);
            }
            if (str_ends_with($url, '/v1/notifications/verify-webhook-signature')) {
                return Http::response(['verification_status' => $this->signatureOk ? 'SUCCESS' : 'FAILURE']);
            }
            if ($r->method() === 'POST' && str_ends_with($url, '/v2/checkout/orders')) {
                $id = 'PP'.Str::upper(Str::random(10));
                $this->orders[$id] = ['id' => $id, 'status' => 'PAYER_ACTION_REQUIRED', 'purchase_units' => [$r['purchase_units'][0]]];

                return Http::response($this->orders[$id] + ['links' => [['rel' => 'payer-action', 'href' => "https://www.sandbox.paypal.com/checkoutnow?token={$id}"]]], 201);
            }
            if (preg_match('#/v2/checkout/orders/(\w+)/capture$#', $url, $m)) {
                $unit = $this->orders[$m[1]]['purchase_units'][0];
                $this->orders[$m[1]]['status'] = 'COMPLETED';
                $this->orders[$m[1]]['purchase_units'][0]['payments'] = ['captures' => [[
                    'id' => 'CAP'.$m[1], 'status' => 'COMPLETED', 'amount' => $unit['amount'], 'custom_id' => $unit['custom_id'],
                ]]];

                return Http::response($this->orders[$m[1]], 201);
            }
            if (preg_match('#/v2/checkout/orders/(\w+)$#', $url, $m)) {
                return Http::response($this->orders[$m[1]]);
            }
            if (preg_match('#/v2/payments/captures/(\w+)/refund$#', $url)) {
                return Http::response(['id' => 'REF1', 'status' => 'COMPLETED'], 201);
            }

            return Http::response(['name' => 'NOT_FOUND'], 404);
        });
    }

    public function test_usd_conversion_uses_the_pegs(): void
    {
        $this->assertSame('31.86', PaypalGateway::toUsd('117.00', 'AED'));
        $this->assertSame('31.20', PaypalGateway::toUsd('117.00', 'SAR'));
    }

    public function test_paypal_checkout_is_captured_on_return_once_and_refunded_in_usd(): void
    {
        $this->getJson('/api/v1/payments/methods', $this->customerAuth())->assertOk()
            ->assertJsonPath('data.0.provider', 'sandbox')->assertJsonPath('data.1.provider', 'paypal')->assertJsonPath('data.1.currency_note', 'USD');

        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $payment = Payment::query()->findOrFail($this->postJson('/api/v1/payments/intent', ['order_id' => $order->id, 'provider' => 'paypal'], $auth + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertCreated()->assertJsonPath('data.provider', 'paypal')->json('data.id'));
        $this->assertStringContainsString('paypal.com/checkoutnow', $payment->redirect_url);
        $ppOrder = $payment->provider_reference;
        $sent = $this->orders[$ppOrder]['purchase_units'][0];
        $this->assertSame('USD', $sent['amount']['currency_code']);
        $this->assertSame(PaypalGateway::toUsd((string) $payment->amount, 'AED'), $sent['amount']['value']);

        // Not approved yet: confirming does nothing.
        $this->postJson("/api/v1/payments/{$payment->id}/confirm", [], $auth)->assertOk()->assertJsonPath('data.status', 'requires_action');

        // Buyer approves on PayPal, comes back: we capture and the order is paid.
        $this->orders[$ppOrder]['status'] = 'APPROVED';
        $this->postJson("/api/v1/payments/{$payment->id}/confirm", [], $auth)->assertOk()->assertJsonPath('data.status', 'captured');
        $this->assertContains($order->refresh()->status, ['paid', 'processing']);

        // The matching webhook is a duplicate; a forged one is rejected.
        $capture = $this->orders[$ppOrder]['purchase_units'][0]['payments']['captures'][0] + ['supplementary_data' => ['related_ids' => ['order_id' => $ppOrder]]];
        $hook = fn () => $this->postJson('/api/v1/webhooks/payments/paypal', ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $capture]);
        $hook()->assertOk()->assertJsonPath('data.result', 'duplicate');
        $this->signatureOk = false;
        $hook()->assertStatus(401);

        // Refund goes to the capture, in USD, rounded down.
        $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '10.00', 'reason_code' => 'goodwill'], $this->asStaff(['finance']) + ['Idempotency-Key' => 'rf-'.Str::random(20)])->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), "/captures/CAP{$ppOrder}/refund") && $r['amount'] === ['currency_code' => 'USD', 'value' => '2.72']);
    }

    public function test_webhook_capture_when_the_buyer_never_returns(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $payment = Payment::query()->findOrFail($this->postJson('/api/v1/payments/intent', ['order_id' => $order->id, 'provider' => 'paypal'], $auth + ['Idempotency-Key' => 'pay-'.Str::random(20)])->json('data.id'));
        $this->orders[$payment->provider_reference]['status'] = 'APPROVED';

        $this->postJson('/api/v1/webhooks/payments/paypal', ['id' => 'WH-2', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => $payment->provider_reference]])
            ->assertOk()->assertJsonPath('data.result', 'processed');
        $this->assertSame('captured', $payment->refresh()->status);
    }
}
