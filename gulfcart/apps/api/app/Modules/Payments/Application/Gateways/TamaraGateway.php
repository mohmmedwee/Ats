<?php

namespace App\Modules\Payments\Application\Gateways;

use App\Modules\Payments\Contracts\ConfirmsOnReturn;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Domain\GatewayRefundResult;
use App\Modules\Payments\Domain\IntentRequest;
use App\Modules\Payments\Domain\IntentResult;
use App\Modules\Payments\Domain\InvalidWebhookSignature;
use App\Modules\Payments\Domain\WebhookEvent;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Tamara: pay in instalments (UAE, KSA).
 *
 * Flow: create a checkout session → customer approves on Tamara → notification "approved" (signed JWT
 * with the notification token) or the customer returns → we authorise, then capture the full amount
 * right away (GulfCart buys from the supplier as soon as the order is paid) → the order is paid.
 * Both paths report the capture with the same event ID, so it is applied once.
 */
final class TamaraGateway implements ConfirmsOnReturn, PaymentGateway
{
    public function name(): string
    {
        return 'tamara';
    }

    public function createIntent(IntentRequest $request, string $idempotencyKey): IntentResult
    {
        $country = $request->countryCode ?? 'AE';
        $money = fn (string $amount) => ['amount' => (float) Decimal::round($amount, 2), 'currency' => $request->currency];
        [$first, $last] = $this->names($request->customerName);
        $return = $request->returnUrl.'?payment='.$request->paymentId;
        $session = $this->json($this->http()->post($this->base().'/checkout', [
            'order_reference_id' => $request->paymentId,
            'order_number' => $request->orderNumber,
            'total_amount' => $money($request->amount),
            'description' => 'GulfCart order '.$request->orderNumber,
            'country_code' => $country,
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'locale' => $request->locale === 'ar' ? 'ar_SA' : 'en_US',
            'items' => [[
                'reference_id' => $request->orderNumber, 'type' => 'Physical', 'name' => 'GulfCart order '.$request->orderNumber,
                'sku' => $request->orderNumber, 'quantity' => 1, 'total_amount' => $money($request->amount),
            ]],
            'consumer' => array_filter(['first_name' => $first, 'last_name' => $last, 'phone_number' => $request->customerPhone, 'email' => $request->customerEmail]),
            'shipping_address' => array_filter([
                'first_name' => $first, 'last_name' => $last, 'line1' => $request->address['address_line_1'] ?? null,
                'line2' => $request->address['address_line_2'] ?? null, 'city' => $request->address['city'] ?? null,
                'country_code' => $country, 'phone_number' => $request->customerPhone,
            ]),
            'tax_amount' => $money('0'),
            'shipping_amount' => $money('0'),
            'merchant_url' => [
                'success' => $return, 'failure' => $return.'&failed=1', 'cancel' => $return.'&cancelled=1',
                'notification' => rtrim((string) (config('gulfcart.payments.tamara.webhook_base_url') ?: config('app.url')), '/').'/api/v1/webhooks/payments/tamara',
            ],
        ]));

        if (! is_string($session['order_id'] ?? null) || ! is_string($session['checkout_url'] ?? null)) {
            throw new ApiException('PAYMENT_METHOD_DECLINED', 422, ['provider' => 'tamara', 'reason' => (string) ($session['message'] ?? 'rejected')], (string) __('errors.bnpl_declined', ['provider' => 'Tamara']));
        }

        return new IntentResult($session['order_id'], 'requires_action', $session['checkout_url']);
    }

    public function confirm(string $providerReference): ?WebhookEvent
    {
        $order = $this->json($this->http()->get($this->base().'/orders/'.$providerReference));

        return $this->settle($order, $providerReference);
    }

    public function refund(string $providerReference, string $amount, string $currency, string $idempotencyKey): GatewayRefundResult
    {
        try {
            $refund = $this->json($this->http()->post($this->base().'/payments/simplified-refund/'.$providerReference, [
                'total_amount' => ['amount' => (float) Decimal::round($amount, 2), 'currency' => $currency],
                'comment' => 'GulfCart refund '.$idempotencyKey,
            ]));
        } catch (RuntimeException $e) {
            return new GatewayRefundResult('failed', null, mb_substr($e->getMessage(), 0, 255));
        }

        return new GatewayRefundResult('succeeded', (string) ($refund['refund_id'] ?? $refund['capture_id'] ?? $idempotencyKey));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $token = (string) ($request->query('tamaraToken') ?: $request->bearerToken());
        if (! self::validJwt($token, (string) config('gulfcart.payments.tamara.notification_token'))) {
            throw new InvalidWebhookSignature('Tamara notification token invalid.');
        }
        $body = (array) $request->json()->all();
        $orderId = (string) ($body['order_id'] ?? '');
        $status = strtolower((string) ($body['order_status'] ?? $body['event_type'] ?? ''));

        return match ($status) {
            'approved', 'authorised', 'order_approved', 'order_authorised' => $this->confirm($orderId) ?? new WebhookEvent('tamara:'.$orderId.':'.$status, 'ignored', $orderId, null, null, $body),
            'declined', 'expired', 'canceled', 'cancelled', 'order_declined', 'order_expired' => new WebhookEvent('tamara:'.$orderId.':'.$status, WebhookEvent::PAYMENT_FAILED, $orderId, null, null, $body, strtoupper($status), 'tamara'),
            default => new WebhookEvent('tamara:'.$orderId.':'.$status, 'ignored', $orderId, null, null, $body),
        };
    }

    /** HS256 JWT signed with the notification token, not expired. */
    public static function validJwt(string $jwt, string $secret): bool
    {
        $parts = explode('.', $jwt);
        if ($secret === '' || count($parts) !== 3) {
            return false;
        }
        $b64 = fn (string $s) => base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        $header = json_decode((string) $b64($parts[0]), true);
        $claims = json_decode((string) $b64($parts[1]), true);
        if (! is_array($header) || ($header['alg'] ?? '') !== 'HS256' || ! is_array($claims)) {
            return false;
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true)), '+/', '-_'), '=');
        if (! hash_equals($expected, $parts[2])) {
            return false;
        }

        return ! isset($claims['exp']) || (int) $claims['exp'] >= time() - 60;
    }

    /** @param array<string, mixed> $order */
    private function settle(array $order, string $orderId): ?WebhookEvent
    {
        $status = strtolower((string) ($order['status'] ?? ''));
        if ($status === 'approved') {
            $this->json($this->http()->post($this->base().'/orders/'.$orderId.'/authorise'));
            $status = 'authorised';
        }
        if ($status === 'authorised') {
            $total = (array) ($order['total_amount'] ?? []);
            $this->json($this->http()->post($this->base().'/payments/capture', [
                'order_id' => $orderId,
                'total_amount' => $total,
                'shipping_info' => ['shipped_at' => now()->toIso8601String(), 'shipping_company' => 'GulfCart'],
            ]));
            $status = 'fully_captured';
        }
        if (in_array($status, ['declined', 'expired', 'canceled', 'cancelled'], true)) {
            return new WebhookEvent('tamara:'.$orderId.':'.$status, WebhookEvent::PAYMENT_FAILED, $orderId, null, null, $order, strtoupper($status), 'tamara');
        }
        if (! in_array($status, ['fully_captured', 'partially_captured'], true)) {
            return null; // customer has not finished yet
        }
        $total = (array) ($order['total_amount'] ?? []);

        return new WebhookEvent('capture:tamara:'.$orderId, WebhookEvent::PAYMENT_CAPTURED, $orderId,
            Decimal::round((string) ($total['amount'] ?? '0'), 2), (string) ($total['currency'] ?? ''), $order, null, 'tamara');
    }

    /** @return array{0: string, 1: string} */
    private function names(?string $full): array
    {
        $parts = preg_split('/\s+/', trim((string) $full)) ?: [];
        $first = array_shift($parts) ?: 'Customer';

        return [$first, $parts === [] ? $first : implode(' ', $parts)];
    }

    private function base(): string
    {
        return config('gulfcart.payments.tamara.mode') === 'live' ? 'https://api.tamara.co' : 'https://api-sandbox.tamara.co';
    }

    private function http(): PendingRequest
    {
        $token = (string) config('gulfcart.payments.tamara.api_token');
        if ($token === '') {
            throw new RuntimeException('TAMARA_API_TOKEN is not configured.');
        }

        return Http::withToken($token)->acceptJson()->asJson()->timeout(20)->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            Log::warning('payments.tamara_error', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            throw new RuntimeException('Tamara API error '.$response->status().': '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 200));
        }

        return (array) $response->json();
    }
}
