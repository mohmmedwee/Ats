<?php

namespace Tests\Feature\Orders;

use App\Modules\Payments\Application\Gateways\TapGateway;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class TapGatewayTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    /** @var array<string, array<string, mixed>> live charges as Tap would return them */
    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['gulfcart.payments.provider' => 'tap', 'gulfcart.payments.tap.secret_key' => 'sk_test_x', 'app.url' => 'https://api.shop.test']);
        $this->app->forgetInstance(PaymentGateway::class);
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/v2/charges')) {
                $id = 'chg_'.Str::random(10);
                $this->charges[$id] = ['id' => $id, 'status' => 'INITIATED', 'amount' => $r['amount'], 'currency' => $r['currency']];

                return Http::response($this->charges[$id] + ['transaction' => ['url' => "https://checkout.tap.test/{$id}"]]);
            }
            if ($r->method() === 'GET' && preg_match('#/v2/charges/(chg_\w+)$#', $r->url(), $m)) {
                return Http::response($this->charges[$m[1]] ?? [], isset($this->charges[$m[1]]) ? 200 : 404);
            }
            if (str_ends_with($r->url(), '/v2/refunds')) {
                return Http::response(['id' => 're_1', 'status' => 'PENDING', 'amount' => $r['amount']]);
            }

            return Http::response([], 404);
        });
    }

    /** @param array<string, mixed> $charge */
    private function tapWebhook(array $charge): TestResponse
    {
        $amount = number_format((float) $charge['amount'], 2, '.', '');
        $hash = hash_hmac('sha256', 'x_id'.$charge['id'].'x_amount'.$amount.'x_currency'.$charge['currency'].'x_gateway_referencegw1x_payment_referencepr1x_status'.$charge['status'].'x_created1700000000000', 'sk_test_x');
        $body = $charge + ['reference' => ['gateway' => 'gw1', 'payment' => 'pr1'], 'transaction' => ['created' => '1700000000000'], 'source' => ['payment_method' => 'MADA']];

        return $this->call('POST', '/api/v1/webhooks/payments/tap', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_HASHSTRING' => $hash], (string) json_encode($body));
    }

    public function test_customer_is_sent_to_tap_and_a_verified_capture_pays_the_order(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);

        $payment = Payment::query()->findOrFail($this->postJson('/api/v1/payments/intent', ['order_id' => $order->id], $auth + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertCreated()->json('data.id'));
        $this->assertSame('tap', $payment->provider);
        $this->assertStringStartsWith('https://checkout.tap.test/', $payment->redirect_url);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/charges') && $r['post']['url'] === 'https://api.shop.test/api/v1/webhooks/payments/tap'
            && $r['reference']['order'] === $order->order_number && $r['source']['id'] === 'src_all');

        // A forged webhook is rejected.
        $this->call('POST', '/api/v1/webhooks/payments/tap', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_HASHSTRING' => 'bad'], '{"id":"chg_x","status":"CAPTURED"}')->assertStatus(401);

        // Signed webhook saying CAPTURED while Tap's API still says INITIATED: nothing happens.
        $charge = $this->charges[$payment->provider_reference];
        $this->tapWebhook(['status' => 'CAPTURED'] + $charge)->assertOk();
        $this->assertSame('pending_payment', $order->refresh()->status);

        // Confirmed by the API: the order is paid.
        $this->charges[$payment->provider_reference]['status'] = 'CAPTURED';
        $this->tapWebhook(['status' => 'CAPTURED'] + $charge)->assertOk()->assertJsonPath('data.result', 'processed');
        $this->assertContains($order->refresh()->status, ['paid', 'processing']);
        $this->assertSame('captured', $payment->refresh()->status);
        $this->tapWebhook(['status' => 'CAPTURED'] + $charge)->assertJsonPath('data.result', 'duplicate');
    }

    public function test_refunds_are_requested_from_tap(): void
    {
        $result = app(TapGateway::class)->refund('chg_1', '25.50', 'AED', 'rf_key_123');
        $this->assertSame('pending', $result->status);
        $this->assertSame('re_1', $result->providerReference);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/refunds') && $r['charge_id'] === 'chg_1' && $r['amount'] === 25.5);
    }
}
