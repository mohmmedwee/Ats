<?php

namespace App\Modules\Payments\Application\Gateways;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Domain\GatewayRefundResult;
use App\Modules\Payments\Domain\IntentRequest;
use App\Modules\Payments\Domain\IntentResult;
use App\Modules\Payments\Domain\InvalidWebhookSignature;
use App\Modules\Payments\Domain\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Development/staging gateway that behaves like a hosted-payment provider:
 * intents redirect to a sandbox page; outcomes arrive as signed webhooks
 * (header X-Sandbox-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "t.body">).
 */
final class SandboxGateway implements PaymentGateway
{
    public function __construct(private readonly string $secret, private readonly int $toleranceSeconds = 300) {}

    public function name(): string
    {
        return 'sandbox';
    }

    public function createIntent(IntentRequest $request, string $idempotencyKey): IntentResult
    {
        return new IntentResult('sbx_pi_'.substr(hash('sha256', $idempotencyKey), 0, 24), 'requires_action', $request->returnUrl.'?payment='.$request->paymentId);
    }

    public function refund(string $providerReference, string $amount, string $currency, string $idempotencyKey): GatewayRefundResult
    {
        return new GatewayRefundResult('succeeded', 'sbx_re_'.substr(hash('sha256', $idempotencyKey), 0, 24));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $header = (string) $request->headers->get('X-Sandbox-Signature', '');
        if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m) !== 1) {
            throw new InvalidWebhookSignature('Missing or malformed signature.');
        }
        if (abs(time() - (int) $m[1]) > $this->toleranceSeconds) {
            throw new InvalidWebhookSignature('Signature timestamp outside tolerance (possible replay).');
        }
        if (! hash_equals(hash_hmac('sha256', $m[1].'.'.$request->getContent(), $this->secret), $m[2])) {
            throw new InvalidWebhookSignature('Signature mismatch.');
        }

        $data = (array) $request->json()->all();

        return new WebhookEvent(
            (string) ($data['id'] ?? ''), (string) ($data['type'] ?? ''), (string) ($data['payment_reference'] ?? ''),
            isset($data['amount']) ? (string) $data['amount'] : null, isset($data['currency']) ? (string) $data['currency'] : null,
            $data, isset($data['failure_code']) ? (string) $data['failure_code'] : null, isset($data['method']) ? (string) $data['method'] : null,
        );
    }

    /** @return array{body: string, signature: string} helper for the sandbox page and tests */
    public function sign(array $payload, ?int $timestamp = null): array
    {
        $payload['id'] ??= 'evt_'.Str::lower(Str::random(20));
        $body = (string) json_encode($payload);
        $t = $timestamp ?? time();

        return ['body' => $body, 'signature' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $this->secret)];
    }
}
