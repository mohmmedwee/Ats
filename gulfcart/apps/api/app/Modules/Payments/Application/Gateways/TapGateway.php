<?php

namespace App\Modules\Payments\Application\Gateways;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Domain\GatewayRefundResult;
use App\Modules\Payments\Domain\IntentRequest;
use App\Modules\Payments\Domain\IntentResult;
use App\Modules\Payments\Domain\InvalidWebhookSignature;
use App\Modules\Payments\Domain\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Tap Payments (https://developers.tap.company), UAE and Saudi Arabia: cards, mada, Apple Pay, Google Pay
 * on Tap's hosted page (src_all), so card data never reaches GulfCart.
 *
 * - Charge: POST /v2/charges, the customer is redirected to transaction.url.
 * - Webhook: Tap posts the charge/refund object to post.url with a `hashstring` header, an HMAC-SHA256
 *   (secret key) of x_id, x_amount, x_currency, x_gateway_reference, x_payment_reference, x_status and
 *   x_created. The status is then re-read from the API before money moves (defence in depth).
 * - Refund: POST /v2/refunds; the outcome also arrives by webhook.
 */
final class TapGateway implements PaymentGateway
{
    private const FAILED = ['FAILED', 'DECLINED', 'CANCELLED', 'RESTRICTED', 'VOID', 'TIMEDOUT', 'ABANDONED', 'UNKNOWN'];

    public function name(): string
    {
        return 'tap';
    }

    public function createIntent(IntentRequest $request, string $idempotencyKey): IntentResult
    {
        $body = [
            'amount' => (float) $request->amount,
            'currency' => $request->currency,
            'threeDSecure' => true,
            'save_card' => false,
            'description' => 'GulfCart order '.$request->orderNumber,
            'statement_descriptor' => 'GulfCart',
            'reference' => ['transaction' => $request->paymentId, 'order' => $request->orderNumber],
            'metadata' => ['payment_id' => $request->paymentId, 'idempotency_key' => $idempotencyKey],
            'receipt' => ['email' => false, 'sms' => false],
            'customer' => array_filter([
                'first_name' => $request->customerName ?: 'Customer',
                'email' => $request->customerEmail,
                'phone' => $request->customerPhone !== null && preg_match('/^\+(\d{3})(\d+)$/', $request->customerPhone, $m)
                    ? ['country_code' => $m[1], 'number' => $m[2]] : null,
            ]),
            'source' => ['id' => 'src_all'],
            'post' => ['url' => $this->webhookUrl()],
            'redirect' => ['url' => $request->returnUrl.'?payment='.$request->paymentId],
        ];

        $charge = $this->call('post', 'charges', $body);
        $url = $charge['transaction']['url'] ?? null;
        if (! is_string($charge['id'] ?? null) || ! is_string($url)) {
            throw new RuntimeException('Tap did not return a payment page.');
        }

        return new IntentResult($charge['id'], 'requires_action', $url);
    }

    public function refund(string $providerReference, string $amount, string $currency, string $idempotencyKey): GatewayRefundResult
    {
        try {
            $refund = $this->call('post', 'refunds', [
                'charge_id' => $providerReference, 'amount' => (float) $amount, 'currency' => $currency,
                'reason' => 'requested_by_customer', 'reference' => ['merchant' => $idempotencyKey],
                'metadata' => ['idempotency_key' => $idempotencyKey], 'post' => ['url' => $this->webhookUrl()],
            ]);
        } catch (RuntimeException $e) {
            return new GatewayRefundResult('failed', null, mb_substr($e->getMessage(), 0, 255));
        }
        $status = strtoupper((string) ($refund['status'] ?? ''));

        return new GatewayRefundResult(
            match (true) {
                $status === 'REFUNDED' => 'succeeded', in_array($status, ['PENDING', 'IN_PROGRESS', 'INITIATED'], true) => 'pending', default => 'failed'
            },
            is_string($refund['id'] ?? null) ? $refund['id'] : null,
            in_array($status, ['REFUNDED', 'PENDING', 'IN_PROGRESS', 'INITIATED'], true) ? null : ($refund['response']['message'] ?? $status),
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = (array) $request->json()->all();
        $id = (string) ($data['id'] ?? '');
        $status = strtoupper((string) ($data['status'] ?? ''));
        $currency = (string) ($data['currency'] ?? '');
        $decimals = in_array($currency, ['KWD', 'BHD', 'OMR', 'JOD'], true) ? 3 : 2;
        $amount = number_format((float) ($data['amount'] ?? 0), $decimals, '.', '');

        $toHash = 'x_id'.$id
            .'x_amount'.$amount
            .'x_currency'.$currency
            .'x_gateway_reference'.($data['reference']['gateway'] ?? '')
            .'x_payment_reference'.($data['reference']['payment'] ?? '')
            .'x_status'.$status
            .'x_created'.($data['transaction']['created'] ?? '');
        $expected = hash_hmac('sha256', $toHash, (string) config('gulfcart.payments.tap.secret_key'));
        if ($id === '' || ! hash_equals($expected, (string) $request->headers->get('hashstring', ''))) {
            throw new InvalidWebhookSignature('Tap hashstring mismatch.');
        }

        $isRefund = str_starts_with($id, 're_');
        if (! $isRefund && $status === 'CAPTURED') {
            // Re-read the charge from Tap: only an API-confirmed capture is trusted.
            $live = $this->call('get', 'charges/'.$id);
            $status = strtoupper((string) ($live['status'] ?? ''));
            $amount = number_format((float) ($live['amount'] ?? 0), $decimals, '.', '');
            $currency = (string) ($live['currency'] ?? $currency);
        }

        $type = match (true) {
            $isRefund && $status === 'REFUNDED' => WebhookEvent::REFUND_SUCCEEDED,
            $isRefund && in_array($status, ['FAILED', 'DECLINED', 'CANCELLED'], true) => WebhookEvent::REFUND_FAILED,
            ! $isRefund && $status === 'CAPTURED' => WebhookEvent::PAYMENT_CAPTURED,
            ! $isRefund && in_array($status, self::FAILED, true) => WebhookEvent::PAYMENT_FAILED,
            default => 'ignored',
        };

        return new WebhookEvent(
            $id.':'.$status, $type, $id, $amount, $currency, $data,
            $type === WebhookEvent::PAYMENT_FAILED || $type === WebhookEvent::REFUND_FAILED ? (string) ($data['response']['code'] ?? $status) : null,
            isset($data['source']['payment_method']) ? strtolower((string) $data['source']['payment_method']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body = []): array
    {
        try {
            $request = Http::withToken((string) config('gulfcart.payments.tap.secret_key'))->acceptJson()->timeout(20);
            $response = $method === 'get'
                ? $request->get('https://api.tap.company/v2/'.$path)
                : $request->asJson()->post('https://api.tap.company/v2/'.$path, $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Tap is unreachable: '.$e->getMessage(), previous: $e);
        }
        if ($response->failed()) {
            Log::warning('payments.tap_error', ['path' => $path, 'status' => $response->status(), 'errors' => $response->json('errors')]);
            throw new RuntimeException('Tap error '.$response->status().': '.json_encode($response->json('errors')));
        }

        return (array) $response->json();
    }

    private function webhookUrl(): string
    {
        return rtrim((string) config('gulfcart.payments.tap.webhook_base_url') ?: (string) config('app.url'), '/').'/api/v1/webhooks/payments/tap';
    }
}
