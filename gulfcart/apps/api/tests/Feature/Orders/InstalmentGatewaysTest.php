<?php

namespace Tests\Feature\Orders;

use App\Modules\Payments\Application\Gateways\TamaraGateway;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

/** Tabby and Tamara against simulated provider APIs (request shapes to be confirmed in their sandboxes). */
final class InstalmentGatewaysTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    /** @var array<string, array<string, mixed>> */
    private array $tabby = [];

    /** @var array<string, array<string, mixed>> */
    private array $tamara = [];

    private bool $tabbyReject = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'gulfcart.payments.methods' => 'tabby,tamara',
            'gulfcart.payments.tabby.secret_key' => 'sk_test', 'gulfcart.payments.tabby.merchant_codes.AE' => 'GCAE', 'gulfcart.payments.tabby.merchant_codes.SA' => 'GCSA',
            'gulfcart.payments.tabby.webhook_secret' => 'tabby-hook-secret',
            'gulfcart.payments.tamara.api_token' => 'tam_test', 'gulfcart.payments.tamara.notification_token' => 'tam-notify-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            // ---- Tabby
            if (str_ends_with($url, '/api/v2/checkout')) {
                if ($this->tabbyReject) {
                    return Http::response(['status' => 'rejected', 'configuration' => ['products' => ['installments' => ['rejection_reason' => 'not_available']]]]);
                }
                $id = 'tp_'.Str::lower(Str::random(8));
                $this->tabby[$id] = ['id' => $id, 'status' => 'CREATED', 'amount' => $r['payment']['amount'], 'currency' => $r['payment']['currency'], 'captures' => [], 'refunds' => [], 'host' => parse_url($url, PHP_URL_HOST)];

                return Http::response(['id' => 'sess_1', 'status' => 'created', 'payment' => ['id' => $id], 'configuration' => ['available_products' => ['installments' => [['web_url' => "https://checkout.tabby.ai/?sessionId={$id}"]]]]]);
            }
            if (preg_match('#/api/v2/payments/(\w+)/captures$#', $url, $m)) {
                $this->tabby[$m[1]]['status'] = 'CLOSED';
                $this->tabby[$m[1]]['captures'][] = ['id' => 'cap_1', 'amount' => $r['amount']];

                return Http::response($this->tabby[$m[1]]);
            }
            if (preg_match('#/api/v2/payments/(\w+)/refunds$#', $url, $m)) {
                $this->tabby[$m[1]]['refunds'][] = ['id' => 'rf_1', 'amount' => $r['amount'], 'reference_id' => $r['reference_id']];

                return Http::response($this->tabby[$m[1]]);
            }
            if (preg_match('#/api/v2/payments/(\w+)$#', $url, $m)) {
                return Http::response($this->tabby[$m[1]]);
            }
            // ---- Tamara
            if (str_ends_with($url, 'api-sandbox.tamara.co/checkout')) {
                $id = 'tam-'.Str::lower(Str::random(8));
                $this->tamara[$id] = ['order_id' => $id, 'status' => 'new', 'total_amount' => $r['total_amount']];

                return Http::response(['order_id' => $id, 'checkout_id' => 'c1', 'checkout_url' => "https://checkout-sandbox.tamara.co/checkout/{$id}"]);
            }
            if (preg_match('#/orders/([\w-]+)/authorise$#', $url, $m)) {
                $this->tamara[$m[1]]['status'] = 'authorised';

                return Http::response(['order_id' => $m[1], 'status' => 'authorised']);
            }
            if (str_ends_with($url, '/payments/capture')) {
                $this->tamara[$r['order_id']]['status'] = 'fully_captured';

                return Http::response(['capture_id' => 'cap-1', 'order_id' => $r['order_id']]);
            }
            if (preg_match('#/payments/simplified-refund/([\w-]+)$#', $url)) {
                return Http::response(['refund_id' => 'tr-1']);
            }
            if (preg_match('#/orders/([\w-]+)$#', $url, $m)) {
                return Http::response($this->tamara[$m[1]]);
            }

            return Http::response(['message' => 'not found'], 404);
        });
    }

    public function test_tabby_redirect_capture_on_return_country_base_url_webhook_secret_and_refund(): void
    {
        ['variant' => $v] = $this->sellableProduct(['SA']);
        $auth = $this->customerAuth();
        $this->assertContains('tabby', array_column($this->getJson('/api/v1/payments/methods', $auth + ['X-Country' => 'SA'])->json('data'), 'provider'));
        $order = $this->placeOrder([[$v, 1]], $auth, 'SA');
        $payment = Payment::query()->findOrFail($this->postJson('/api/v1/payments/intent', ['order_id' => $order->id, 'provider' => 'tabby'], $auth + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertCreated()->json('data.id'));
        $this->assertStringContainsString('checkout.tabby.ai', (string) $payment->redirect_url);
        $this->assertStringStartsWith('SA:', (string) $payment->provider_reference);
        [, $id] = explode(':', (string) $payment->provider_reference);
        $this->assertSame('api.tabby.sa', $this->tabby[$id]['host']); // Saudi payments go to the KSA host
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/v2/checkout') && $r->header('X-Merchant-Code') === ['GCSA'] && $r['payment']['currency'] === 'SAR');

        $this->postJson("/api/v1/payments/{$payment->id}/confirm", [], $auth)->assertOk()->assertJsonPath('data.status', 'requires_action');
        $this->tabby[$id]['status'] = 'AUTHORIZED';
        $this->postJson("/api/v1/payments/{$payment->id}/confirm", [], $auth)->assertOk()->assertJsonPath('data.status', 'captured');
        $this->assertNotNull($order->refresh()->paid_at);

        // Same capture by webhook: duplicate. Wrong secret: rejected.
        $hook = fn (string $secret) => $this->postJson('/api/v1/webhooks/payments/tabby', ['id' => $id, 'status' => 'closed', 'currency' => 'SAR', 'amount' => $this->tabby[$id]['amount'], 'captures' => $this->tabby[$id]['captures']], ['X-GulfCart-Webhook' => $secret]);
        $hook('tabby-hook-secret')->assertOk()->assertJsonPath('data.result', 'duplicate');
        $hook('wrong')->assertStatus(401);

        $this->resetAuth();
        $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '10.00', 'reason_code' => 'goodwill'], $this->asStaff(['finance']) + ['Idempotency-Key' => 'rf-'.Str::random(20)])->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), "api.tabby.sa/api/v2/payments/{$id}/refunds") && $r['amount'] === '10.00');
    }

    public function test_tabby_prescoring_rejection_is_a_clear_error(): void
    {
        $this->tabbyReject = true;
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $this->postJson('/api/v1/payments/intent', ['order_id' => $order->id, 'provider' => 'tabby'], $auth + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'PAYMENT_METHOD_DECLINED');
    }

    public function test_tamara_signed_notification_authorises_and_captures(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $payment = Payment::query()->findOrFail($this->postJson('/api/v1/payments/intent', ['order_id' => $order->id, 'provider' => 'tamara'], $auth + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertCreated()->json('data.id'));
        $this->assertStringContainsString('tamara.co/checkout', (string) $payment->redirect_url);
        $tid = (string) $payment->provider_reference;
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout') && $r['order_reference_id'] === $payment->id && str_ends_with($r['merchant_url']['notification'], '/webhooks/payments/tamara'));

        $jwt = function (string $secret, int $exp) {
            $b = fn ($v) => rtrim(strtr(base64_encode((string) json_encode($v)), '+/', '-_'), '=');
            $head = $b(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$b(['exp' => $exp, 'iat' => time()]);

            return $head.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $head, $secret, true)), '+/', '-_'), '=');
        };
        $this->assertFalse(TamaraGateway::validJwt($jwt('other', time() + 300), 'tam-notify-secret'));
        $this->assertFalse(TamaraGateway::validJwt($jwt('tam-notify-secret', time() - 600), 'tam-notify-secret'));

        $this->tamara[$tid]['status'] = 'approved';
        $this->postJson('/api/v1/webhooks/payments/tamara?tamaraToken='.$jwt('forged', time() + 300), ['order_id' => $tid, 'order_status' => 'approved'])->assertStatus(401);
        $this->postJson('/api/v1/webhooks/payments/tamara?tamaraToken='.$jwt('tam-notify-secret', time() + 300), ['order_id' => $tid, 'order_reference_id' => $payment->id, 'order_status' => 'approved'])
            ->assertOk()->assertJsonPath('data.result', 'processed');
        $this->assertSame('captured', $payment->refresh()->status);
        $this->assertNotNull($order->refresh()->paid_at);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), "/orders/{$tid}/authorise"));

        // The customer coming back afterwards changes nothing.
        $this->postJson("/api/v1/payments/{$payment->id}/confirm", [], $auth)->assertOk()->assertJsonPath('data.status', 'captured');
    }
}
