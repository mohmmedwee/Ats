<?php

namespace App\Modules\Payments\Application\Gateways;

use App\Modules\Payments\Contracts\ConfirmsOnReturn;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Domain\GatewayRefundResult;
use App\Modules\Payments\Domain\IntentRequest;
use App\Modules\Payments\Domain\IntentResult;
use App\Modules\Payments\Domain\InvalidWebhookSignature;
use App\Modules\Payments\Domain\WebhookEvent;
use App\Shared\Money\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayPal Checkout (Orders v2, https://developer.paypal.com/docs/api/orders/v2/).
 *
 * PayPal does not settle in AED or SAR, so orders are charged in USD at the fixed pegs (1 USD = 3.6725
 * AED = 3.75 SAR). The original amount travels in purchase_units.custom_id ("payment|amount|currency")
 * and every capture is checked against it before the order is marked paid.
 *
 * Flow: create order → buyer approves on PayPal → we capture (on return, or on CHECKOUT.ORDER.APPROVED
 * if the buyer never comes back) → PAYMENT.CAPTURE.COMPLETED webhook. Captures use the event ID
 * "capture:{id}" on both paths, so each is applied once.
 */
final class PaypalGateway implements ConfirmsOnReturn, PaymentGateway
{
    public function name(): string
    {
        return 'paypal';
    }

    public function createIntent(IntentRequest $request, string $idempotencyKey): IntentResult
    {
        $usd = self::toUsd($request->amount, $request->currency);
        $arabic = str_contains($request->returnUrl, '/ar/');
        $return = $request->returnUrl.'?payment='.$request->paymentId;

        $order = $this->json($this->http(['PayPal-Request-Id' => $idempotencyKey])->post($this->base().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->paymentId,
                'custom_id' => $request->paymentId.'|'.$request->amount.'|'.$request->currency,
                'invoice_id' => $request->orderNumber.'-'.substr($request->paymentId, -8),
                'description' => 'GulfCart order '.$request->orderNumber,
                'amount' => ['currency_code' => 'USD', 'value' => $usd],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => 'GulfCart',
                'locale' => $arabic ? 'ar-AE' : 'en-AE',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => $return,
                'cancel_url' => $return.'&cancelled=1',
            ]]],
        ]));

        $approve = collect($order['links'] ?? [])->firstWhere('rel', 'payer-action')['href']
            ?? collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
        if (! is_string($order['id'] ?? null) || ! is_string($approve)) {
            throw new RuntimeException('PayPal did not return an approval link.');
        }

        return new IntentResult($order['id'], 'requires_action', $approve);
    }

    public function confirm(string $providerReference): ?WebhookEvent
    {
        $order = $this->json($this->http()->get($this->base().'/v2/checkout/orders/'.$providerReference));
        $status = (string) ($order['status'] ?? '');

        if ($status === 'APPROVED') {
            $response = $this->http(['PayPal-Request-Id' => 'capture-'.$providerReference])
                ->withBody('{}', 'application/json')->post($this->base().'/v2/checkout/orders/'.$providerReference.'/capture');
            $issue = (string) ($response->json('details.0.issue') ?? '');
            if ($response->status() === 422 && $issue !== 'ORDER_ALREADY_CAPTURED') {
                return new WebhookEvent('order:'.$providerReference.':'.$issue, WebhookEvent::PAYMENT_FAILED, $providerReference, null, null, (array) $response->json(), $issue ?: 'PAYPAL_DECLINED', 'paypal');
            }
            $order = $issue === 'ORDER_ALREADY_CAPTURED'
                ? $this->json($this->http()->get($this->base().'/v2/checkout/orders/'.$providerReference))
                : $this->json($response);
            $status = (string) ($order['status'] ?? '');
        }

        if ($status === 'VOIDED') {
            return new WebhookEvent('order:'.$providerReference.':voided', WebhookEvent::PAYMENT_FAILED, $providerReference, null, null, $order, 'VOIDED', 'paypal');
        }
        $unit = $order['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;
        if ($status !== 'COMPLETED' || ! is_array($capture)) {
            return null; // buyer has not approved yet
        }
        $capture['custom_id'] ??= $unit['custom_id'] ?? null;

        return $this->captureEvent($capture, $providerReference);
    }

    public function refund(string $providerReference, string $amount, string $currency, string $idempotencyKey): GatewayRefundResult
    {
        try {
            $order = $this->json($this->http()->get($this->base().'/v2/checkout/orders/'.$providerReference));
            $captureId = $order['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
            if (! is_string($captureId)) {
                return new GatewayRefundResult('failed', null, 'No PayPal capture for this payment.');
            }
            // Rounded down so several partial refunds never exceed the USD captured.
            $usd = self::toUsd($amount, $currency, RoundingMode::Down);
            $refund = $this->json($this->http(['PayPal-Request-Id' => $idempotencyKey])->post($this->base().'/v2/payments/captures/'.$captureId.'/refund', [
                'amount' => ['currency_code' => 'USD', 'value' => $usd],
                'note_to_payer' => 'GulfCart refund',
            ]));
        } catch (RuntimeException $e) {
            return new GatewayRefundResult('failed', null, mb_substr($e->getMessage(), 0, 255));
        }
        $status = (string) ($refund['status'] ?? '');

        return new GatewayRefundResult(
            match ($status) {
                'COMPLETED' => 'succeeded', 'PENDING' => 'pending', default => 'failed'
            },
            is_string($refund['id'] ?? null) ? $refund['id'] : null,
            in_array($status, ['COMPLETED', 'PENDING'], true) ? null : $status,
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $event = (array) $request->json()->all();
        $verify = $this->json($this->http()->post($this->base().'/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('paypal-auth-algo'),
            'cert_url' => $request->header('paypal-cert-url'),
            'transmission_id' => $request->header('paypal-transmission-id'),
            'transmission_sig' => $request->header('paypal-transmission-sig'),
            'transmission_time' => $request->header('paypal-transmission-time'),
            'webhook_id' => (string) config('gulfcart.payments.paypal.webhook_id'),
            'webhook_event' => $event === [] ? (object) [] : $event,
        ]));
        if (($verify['verification_status'] ?? '') !== 'SUCCESS') {
            throw new InvalidWebhookSignature('PayPal webhook signature not verified.');
        }

        $type = (string) ($event['event_type'] ?? '');
        $resource = (array) ($event['resource'] ?? []);
        $eventId = (string) ($event['id'] ?? '');

        if ($type === 'CHECKOUT.ORDER.APPROVED' && is_string($resource['id'] ?? null)) {
            // The buyer approved but may never return to the store: capture now.
            return $this->confirm($resource['id']) ?? new WebhookEvent($eventId, 'ignored', (string) $resource['id'], null, null, $event);
        }
        $orderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');

        return match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->captureEvent($resource, $orderId),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => new WebhookEvent($eventId, WebhookEvent::PAYMENT_FAILED, $orderId, null, null, $event, $type, 'paypal'),
            'PAYMENT.CAPTURE.REFUNDED' => new WebhookEvent($eventId, WebhookEvent::REFUND_SUCCEEDED, (string) ($resource['id'] ?? ''), null, null, $event),
            default => new WebhookEvent($eventId, 'ignored', (string) ($resource['id'] ?? ''), null, null, $event),
        };
    }

    /** USD amount for an AED/SAR total at the fixed peg. */
    public static function toUsd(string $amount, string $currency, RoundingMode $mode = RoundingMode::HalfUp): string
    {
        if ($currency === 'USD') {
            return Decimal::round($amount, 2);
        }
        $peg = (string) (config("gulfcart.payments.paypal.usd_pegs.{$currency}") ?? throw new RuntimeException("PayPal cannot charge {$currency}."));

        return Decimal::round(Decimal::div($amount, $peg, 8), 2, $mode);
    }

    /** @param array<string, mixed> $capture */
    private function captureEvent(array $capture, string $orderId): WebhookEvent
    {
        $id = (string) ($capture['id'] ?? '');
        $status = (string) ($capture['status'] ?? '');
        if ($status !== 'COMPLETED') {
            return new WebhookEvent('capture:'.$id.':'.$status, $status === 'PENDING' ? 'ignored' : WebhookEvent::PAYMENT_FAILED, $orderId, null, null, $capture, $status, 'paypal');
        }
        $paidUsd = (string) ($capture['amount']['value'] ?? '0');
        $paidCurrency = (string) ($capture['amount']['currency_code'] ?? '');
        [, $amount, $currency] = array_pad(explode('|', (string) ($capture['custom_id'] ?? '')), 3, null);

        // Report the original AED/SAR amount only when the USD captured matches it exactly; otherwise the
        // USD figure goes through and the payment service flags the mismatch for review.
        $matches = $amount !== null && $currency !== null && $paidCurrency === 'USD'
            && Decimal::cmp($paidUsd, self::toUsd($amount, $currency)) === 0;

        return new WebhookEvent(
            'capture:'.$id, WebhookEvent::PAYMENT_CAPTURED, $orderId,
            $matches ? $amount : $paidUsd, $matches ? $currency : $paidCurrency, $capture, null, 'paypal',
        );
    }

    /** @param array<string, string> $headers */
    private function http(array $headers = []): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->asJson()->timeout(20)->withHeaders($headers);
    }

    private function token(): string
    {
        $id = (string) config('gulfcart.payments.paypal.client_id');
        $secret = (string) config('gulfcart.payments.paypal.client_secret');

        return Cache::remember('paypal.token.'.sha1($id.$this->base()), 3000, function () use ($id, $secret) {
            try {
                $response = Http::asForm()->withBasicAuth($id, $secret)->timeout(15)->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            } catch (ConnectionException $e) {
                throw new RuntimeException('PayPal is unreachable.', previous: $e);
            }
            $token = $response->json('access_token');
            if (! is_string($token)) {
                throw new RuntimeException('PayPal authentication failed (HTTP '.$response->status().').');
            }

            return $token;
        });
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            Log::warning('payments.paypal_error', ['status' => $response->status(), 'issue' => $response->json('details.0.issue'), 'debug_id' => $response->json('debug_id')]);
            throw new RuntimeException('PayPal error '.$response->status().': '.($response->json('details.0.issue') ?? $response->json('name') ?? 'unknown'));
        }

        return (array) $response->json();
    }

    private function base(): string
    {
        return config('gulfcart.payments.paypal.mode') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }
}
