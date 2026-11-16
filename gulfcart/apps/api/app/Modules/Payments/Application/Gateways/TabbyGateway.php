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
 * Tabby: split in 4, no interest (API v2). UAE/Kuwait use api.tabby.ai, Saudi Arabia api.tabby.sa:
 * every call for a payment goes to the base URL of its country.
 *
 * Flow: create a checkout session → customer approves on Tabby (web_url) → the payment is AUTHORIZED →
 * we capture the full amount (on return, or on the "authorized" webhook if the customer never comes
 * back) → CLOSED. Both paths report the capture with the same event ID, so it is applied once.
 * The amount and currency reported are Tabby's, checked against the payment by the payment service.
 *
 * Webhooks carry no signature: register them with a secret header (php artisan payments:tabby-webhook),
 * which is compared here in constant time.
 */
final class TabbyGateway implements ConfirmsOnReturn, PaymentGateway
{
    public function name(): string
    {
        return 'tabby';
    }

    public function createIntent(IntentRequest $request, string $idempotencyKey): IntentResult
    {
        $country = $request->countryCode ?? 'AE';
        $return = $request->returnUrl.'?payment='.$request->paymentId;
        $session = $this->json($this->http($country)->post($this->base($country).'/api/v2/checkout', [
            'payment' => [
                'amount' => Decimal::round($request->amount, 2),
                'currency' => $request->currency,
                'description' => 'GulfCart order '.$request->orderNumber,
                'buyer' => array_filter(['name' => $request->customerName, 'phone' => $request->customerPhone, 'email' => $request->customerEmail]),
                'shipping_address' => array_filter([
                    'city' => $request->address['city'] ?? null,
                    'address' => trim(($request->address['address_line_1'] ?? '').' '.($request->address['address_line_2'] ?? '')),
                    'zip' => $request->address['postal_code'] ?? null,
                ]),
                'order' => ['reference_id' => $request->paymentId, 'items' => [[
                    'title' => 'GulfCart order '.$request->orderNumber, 'quantity' => 1,
                    'unit_price' => Decimal::round($request->amount, 2), 'reference_id' => $request->orderNumber, 'category' => 'general',
                ]]],
            ],
            'lang' => $request->locale === 'ar' ? 'ar' : 'en',
            'merchant_code' => $this->merchantCode($country),
            'merchant_urls' => ['success' => $return, 'cancel' => $return.'&cancelled=1', 'failure' => $return.'&failed=1'],
        ]));

        $paymentId = $session['payment']['id'] ?? null;
        $url = $session['web_url'] ?? ($session['configuration']['available_products']['installments'][0]['web_url'] ?? null);
        if (($session['status'] ?? '') === 'rejected' || ! is_string($paymentId) || ! is_string($url)) {
            // Tabby's pre-scoring declined this customer or basket: offer another method.
            throw new ApiException('PAYMENT_METHOD_DECLINED', 422, ['provider' => 'tabby', 'reason' => (string) ($session['configuration']['products']['installments']['rejection_reason'] ?? 'rejected')], (string) __('errors.bnpl_declined', ['provider' => 'Tabby']));
        }

        // The country is kept in the reference so capture, refund and webhooks use the right base URL.
        return new IntentResult($country.':'.$paymentId, 'requires_action', $url);
    }

    public function confirm(string $providerReference): ?WebhookEvent
    {
        [$country, $id] = $this->split($providerReference);
        $payment = $this->json($this->http($country)->get($this->base($country).'/api/v2/payments/'.$id));

        return $this->settle($payment, $providerReference);
    }

    public function refund(string $providerReference, string $amount, string $currency, string $idempotencyKey): GatewayRefundResult
    {
        [$country, $id] = $this->split($providerReference);
        try {
            $payment = $this->json($this->http($country)->post($this->base($country).'/api/v2/payments/'.$id.'/refunds', [
                'amount' => Decimal::round($amount, 2), 'reference_id' => $idempotencyKey,
            ]));
        } catch (RuntimeException $e) {
            return new GatewayRefundResult('failed', null, mb_substr($e->getMessage(), 0, 255));
        }
        $refund = collect($payment['refunds'] ?? [])->firstWhere('reference_id', $idempotencyKey) ?? collect($payment['refunds'] ?? [])->last();

        return new GatewayRefundResult('succeeded', is_array($refund) ? (string) ($refund['id'] ?? $idempotencyKey) : $idempotencyKey);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $secret = (string) config('gulfcart.payments.tabby.webhook_secret');
        $header = (string) config('gulfcart.payments.tabby.webhook_header', 'X-GulfCart-Webhook');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header($header))) {
            throw new InvalidWebhookSignature('Tabby webhook secret header missing or wrong.');
        }
        $body = (array) $request->json()->all();
        $id = (string) ($body['id'] ?? '');
        $country = $this->countryOf($body);
        $reference = $country.':'.$id;
        $status = strtolower((string) ($body['status'] ?? ''));

        return match ($status) {
            // Authorized but not yet captured: capture now (the customer may never return to the store).
            'authorized' => $this->confirm($reference) ?? new WebhookEvent('tabby:'.$id.':authorized', 'ignored', $reference, null, null, $body),
            'closed' => $this->settle($body, $reference) ?? new WebhookEvent('tabby:'.$id.':closed', 'ignored', $reference, null, null, $body),
            'rejected', 'expired' => new WebhookEvent('tabby:'.$id.':'.$status, WebhookEvent::PAYMENT_FAILED, $reference, null, null, $body, strtoupper($status), 'tabby'),
            default => new WebhookEvent('tabby:'.$id.':'.$status, 'ignored', $reference, null, null, $body),
        };
    }

    /** @param array<string, mixed> $payment */
    private function settle(array $payment, string $reference): ?WebhookEvent
    {
        [$country, $id] = $this->split($reference);
        $status = strtoupper((string) ($payment['status'] ?? ''));
        if ($status === 'AUTHORIZED') {
            $payment = $this->json($this->http($country)->post($this->base($country).'/api/v2/payments/'.$id.'/captures', [
                'amount' => (string) ($payment['amount'] ?? '0'), 'reference_id' => 'capture-'.$id,
            ]));
            $status = strtoupper((string) ($payment['status'] ?? ''));
        }
        if (in_array($status, ['REJECTED', 'EXPIRED'], true)) {
            return new WebhookEvent('tabby:'.$id.':'.strtolower($status), WebhookEvent::PAYMENT_FAILED, $reference, null, null, $payment, $status, 'tabby');
        }
        if ($status !== 'CLOSED') {
            return null; // still waiting for the customer
        }
        $captured = Decimal::sum(array_map(fn ($c) => (string) ($c['amount'] ?? '0'), (array) ($payment['captures'] ?? [])), 2);

        return new WebhookEvent('capture:tabby:'.$id, WebhookEvent::PAYMENT_CAPTURED, $reference,
            Decimal::isZero($captured) ? Decimal::round((string) ($payment['amount'] ?? '0'), 2) : $captured,
            (string) ($payment['currency'] ?? ''), $payment, null, 'tabby');
    }

    /** @return array{0: string, 1: string} */
    private function split(string $reference): array
    {
        return str_contains($reference, ':') ? explode(':', $reference, 2) : ['AE', $reference];
    }

    /** @param array<string, mixed> $body */
    private function countryOf(array $body): string
    {
        return ($body['currency'] ?? '') === 'SAR' ? 'SA' : (($body['currency'] ?? '') === 'KWD' ? 'KW' : 'AE');
    }

    private function base(string $country): string
    {
        $bases = (array) config('gulfcart.payments.tabby.base_urls');

        return rtrim((string) ($bases[$country] ?? $bases['AE'] ?? 'https://api.tabby.ai'), '/');
    }

    private function merchantCode(string $country): string
    {
        return (string) (config("gulfcart.payments.tabby.merchant_codes.{$country}") ?? '');
    }

    private function http(string $country): PendingRequest
    {
        $key = (string) config('gulfcart.payments.tabby.secret_key');
        if ($key === '') {
            throw new RuntimeException('TABBY_SECRET_KEY is not configured.');
        }

        return Http::withToken($key)->acceptJson()->asJson()->timeout(20)->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->withHeaders(['X-Merchant-Code' => $this->merchantCode($country)]);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            Log::warning('payments.tabby_error', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            throw new RuntimeException('Tabby API error '.$response->status().': '.mb_substr((string) ($response->json('error') ?? $response->json('message') ?? $response->body()), 0, 200));
        }

        return (array) $response->json();
    }
}
