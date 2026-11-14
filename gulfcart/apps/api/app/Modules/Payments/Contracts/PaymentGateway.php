<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Domain\GatewayRefundResult;
use App\Modules\Payments\Domain\IntentRequest;
use App\Modules\Payments\Domain\IntentResult;
use App\Modules\Payments\Domain\InvalidWebhookSignature;
use App\Modules\Payments\Domain\WebhookEvent;
use Illuminate\Http\Request;

/**
 * Payment provider integration. Card data never reaches GulfCart: customers pay on the
 * provider's hosted page / SDK (PCI SAQ-A). The provider is chosen in decision D2.
 */
interface PaymentGateway
{
    public function name(): string;

    public function createIntent(IntentRequest $request, string $idempotencyKey): IntentResult;

    public function refund(string $providerReference, string $amount, string $currency, string $idempotencyKey): GatewayRefundResult;

    /** Verifies signature and freshness, then normalizes the event. @throws InvalidWebhookSignature */
    public function parseWebhook(Request $request): WebhookEvent;
}
