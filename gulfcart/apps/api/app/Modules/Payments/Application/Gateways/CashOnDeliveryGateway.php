<?php

namespace App\Modules\Payments\Application\Gateways;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Domain\GatewayRefundResult;
use App\Modules\Payments\Domain\IntentRequest;
use App\Modules\Payments\Domain\IntentResult;
use App\Modules\Payments\Domain\InvalidWebhookSignature;
use App\Modules\Payments\Domain\WebhookEvent;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * Cash on delivery. No provider: the "intent" waits for the customer's confirmation, and staff
 * record the cash when the courier remits it. Nothing to refund online: a refused parcel is
 * simply cancelled; a refund after collection is paid back manually.
 */
final class CashOnDeliveryGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'cod';
    }

    public function createIntent(IntentRequest $request, string $idempotencyKey): IntentResult
    {
        return new IntentResult('cod_'.$request->paymentId, 'requires_action', null);
    }

    public function refund(string $providerReference, string $amount, string $currency, string $idempotencyKey): GatewayRefundResult
    {
        throw new ApiException('REFUND_MANUAL_REQUIRED', 422, ['reason' => 'cash_on_delivery'], 'Cash on delivery refunds are paid back manually (bank transfer or store credit).');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw new InvalidWebhookSignature('Cash on delivery has no webhooks.');
    }
}
