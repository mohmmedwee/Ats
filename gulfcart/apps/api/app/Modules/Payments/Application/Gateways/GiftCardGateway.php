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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Store-value gift cards. Redemption happens inside GulfCart (PaymentService::payWithGiftCard), so there
 * is no hosted page or webhook; refunds credit the card again (once per refund).
 */
final class GiftCardGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'giftcard';
    }

    public function createIntent(IntentRequest $request, string $idempotencyKey): IntentResult
    {
        throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['provider' => ['Apply gift cards with POST /payments/gift-card.']]]);
    }

    public function refund(string $providerReference, string $amount, string $currency, string $idempotencyKey): GatewayRefundResult
    {
        return DB::transaction(function () use ($providerReference, $amount, $idempotencyKey) {
            $card = DB::table('gift_cards')->where('id', $providerReference)->lockForUpdate()->first();
            if ($card === null) {
                return new GatewayRefundResult('failed', null, 'Gift card not found.');
            }
            $inserted = DB::table('gift_card_transactions')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'gift_card_id' => $card->id, 'type' => 'refund', 'amount' => $amount, 'reference' => $idempotencyKey, 'created_at' => now(),
            ]);
            if ($inserted > 0) {
                DB::table('gift_cards')->where('id', $card->id)->update(['balance' => DB::raw('LEAST(initial_amount, balance + '.((float) $amount).')'), 'updated_at' => now()]);
            }

            return new GatewayRefundResult('succeeded', 'gcr_'.$idempotencyKey);
        });
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw new InvalidWebhookSignature('Gift cards have no webhooks.');
    }
}
