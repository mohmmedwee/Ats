<?php

namespace App\Modules\Payments\Application;

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Payments\Contracts\ConfirmsOnReturn;
use App\Modules\Payments\Domain\IntentRequest;
use App\Modules\Payments\Domain\WebhookEvent;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Models\PaymentWebhookEvent;
use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use App\Shared\Outbox\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class PaymentService
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly OrderBook $orders,
        private readonly RefundService $refunds,
        private readonly SmsSender $sms,
    ) {}

    public function createIntent(string $userId, string $orderId, string $idempotencyKey, ?string $provider = null): Payment
    {
        $gateway = $this->gateways->forCustomer($provider);
        $order = $this->orders->get($orderId);
        if ($order === null || $order->userId !== $userId) {
            throw new ApiException('NOT_FOUND', 404);
        }
        if ($order->status === 'expired' || $order->status === 'cancelled') {
            throw new ApiException('ORDER_NOT_PAYABLE', 409, ['status' => $order->status]);
        }
        $due = Decimal::sub($order->grandTotal, $order->paidTotal, 2);
        if (! in_array($order->status, ['pending_payment', 'payment_failed'], true) || Decimal::cmp($due, '0') <= 0) {
            throw new ApiException('ORDER_ALREADY_PAID', 409);
        }

        if ($gateway->name() === 'cod') {
            $this->assertCodEligible($order->userId, $order->paymentMethod);
        }

        $open = Payment::query()->where('order_id', $orderId)->whereIn('status', ['created', 'requires_action'])
            ->where('provider', $gateway->name())->where('amount', $due)->latest()->first();
        if ($open !== null) {
            return $open; // reuse the open intent instead of creating parallel ones
        }

        $payment = Payment::query()->create([
            'order_id' => $orderId, 'provider' => $gateway->name(), 'idempotency_key' => 'pay_'.$idempotencyKey,
            'amount' => $due, 'currency' => $order->currency, 'status' => 'created',
        ]);

        $returnUrl = rtrim((string) config('gulfcart.frontend_url'), '/').($order->locale === 'ar' ? '/ar' : '').'/payment/return';
        $result = $gateway->createIntent(new IntentRequest(
            $payment->id, $due, $order->currency, $order->orderNumber, $returnUrl,
            $order->shippingAddress['full_name'] ?? null, null, self::e164($order->shippingAddress['phone'] ?? null, $order->countryCode) ?? ($order->shippingAddress['phone'] ?? null),
            $order->countryCode, $order->shippingAddress, $order->locale,
        ), $payment->idempotency_key);
        $payment->update(['provider_reference' => $result->providerReference, 'status' => $result->status, 'redirect_url' => $result->redirectUrl]);

        if ($gateway->name() === 'cod') {
            if (config('gulfcart.cod.require_otp', true)) {
                $this->sendCodCode($payment, $order->shippingAddress['phone'] ?? null, $order->countryCode, $order->orderNumber, $order->locale);
            } else {
                $this->markCodConfirmed($payment);
            }
        }

        return $payment->refresh();
    }

    /**
     * Pays (part of) an order from a gift card in the order currency. The card is debited and the payment
     * captured at once; anything left is paid with another method. Same key twice = same payment.
     */
    public function payWithGiftCard(string $userId, string $orderId, string $code, string $idempotencyKey): Payment
    {
        $order = $this->orders->get($orderId);
        if ($order === null || $order->userId !== $userId) {
            throw new ApiException('NOT_FOUND', 404);
        }
        $existing = Payment::query()->where('idempotency_key', 'gc_'.$idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $due = Decimal::sub($order->grandTotal, $order->paidTotal, 2);
        if (! in_array($order->status, ['pending_payment', 'payment_failed'], true) || Decimal::cmp($due, '0') <= 0) {
            throw new ApiException('ORDER_ALREADY_PAID', 409);
        }

        return DB::transaction(function () use ($order, $code, $due, $idempotencyKey) {
            $card = DB::table('gift_cards')->where('code', strtoupper(trim($code)))->lockForUpdate()->first();
            $invalid = fn (string $reason) => new ApiException('GIFT_CARD_INVALID', 422, ['reason' => $reason], (string) __("errors.gift_card_{$reason}"));
            if ($card === null || ! $card->is_active) {
                throw $invalid('not_found');
            }
            if ($card->expires_at !== null && now()->greaterThan($card->expires_at)) {
                throw $invalid('expired');
            }
            if ($card->currency !== $order->currency) {
                throw $invalid('currency');
            }
            if (Decimal::cmp((string) $card->balance, '0') <= 0) {
                throw $invalid('empty');
            }
            $amount = Decimal::cmp((string) $card->balance, $due) < 0 ? Decimal::round((string) $card->balance, 2) : $due;
            $payment = Payment::query()->create([
                'order_id' => $order->id, 'provider' => 'giftcard', 'provider_reference' => $card->id, 'idempotency_key' => 'gc_'.$idempotencyKey,
                'amount' => $amount, 'currency' => $order->currency, 'status' => 'captured', 'method' => 'gift_card', 'paid_at' => now(),
            ]);
            DB::table('gift_cards')->where('id', $card->id)->update(['balance' => Decimal::sub((string) $card->balance, $amount, 2), 'updated_at' => now()]);
            DB::table('gift_card_transactions')->insert(['id' => (string) Str::uuid7(), 'gift_card_id' => $card->id, 'type' => 'redeem', 'amount' => '-'.$amount, 'payment_id' => $payment->id, 'order_id' => $order->id, 'created_at' => now()]);
            $this->orders->recordPayment($order->id, $payment->id, $amount);
            Outbox::record('payment', $payment->id, 'payment.captured', ['payment_id' => $payment->id, 'order_id' => $order->id, 'amount' => $amount, 'currency' => $order->currency]);

            return $payment;
        });
    }

    /** Customer types the SMS code: the order is confirmed and goes to fulfilment. */
    public function confirmCashOnDelivery(Payment $payment, string $code): Payment
    {
        if ($payment->provider !== 'cod' || $payment->status !== 'requires_action') {
            throw new ApiException('COD_NOT_PENDING', 409);
        }
        if ($payment->confirmation_attempts >= 5) {
            throw new ApiException('COD_CODE_LOCKED', 429, [], (string) __('errors.cod_code_locked'));
        }
        if ($payment->confirmation_code_hash === null || ! Hash::check(trim($code), $payment->confirmation_code_hash)) {
            $payment->increment('confirmation_attempts');
            throw new ApiException('COD_CODE_INVALID', 422, [], (string) __('errors.cod_code_invalid'));
        }
        $this->markCodConfirmed($payment);

        return $payment->refresh();
    }

    public function resendCashOnDeliveryCode(Payment $payment): void
    {
        $order = $this->orders->get($payment->order_id);
        if ($order === null || $payment->provider !== 'cod' || $payment->status !== 'requires_action') {
            throw new ApiException('COD_NOT_PENDING', 409);
        }
        $this->sendCodCode($payment, $order->shippingAddress['phone'] ?? null, $order->countryCode, $order->orderNumber, $order->locale);
    }

    /** Staff: the courier remitted the cash. */
    public function recordCashCollected(string $orderId, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($orderId, $note) {
            $payment = Payment::query()->where('order_id', $orderId)->where('provider', 'cod')->where('status', 'pending_collection')->lockForUpdate()->first()
                ?? throw new ApiException('COD_NOT_COLLECTABLE', 409);
            $payment->update(['status' => 'captured', 'paid_at' => now(), 'method' => 'cash']);
            $this->orders->recordCashCollected($orderId, $payment->id, (string) $payment->amount);
            Outbox::record('payment', $payment->id, 'payment.captured', ['payment_id' => $payment->id, 'order_id' => $orderId, 'amount' => $payment->amount, 'currency' => $payment->currency, 'note' => $note]);

            return $payment;
        });
    }

    /** Staff: the customer refused the parcel. The order is cancelled and the refusal counted. */
    public function recordCashRefused(string $orderId, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($orderId, $note) {
            $payment = Payment::query()->where('order_id', $orderId)->where('provider', 'cod')->where('status', 'pending_collection')->lockForUpdate()->first()
                ?? throw new ApiException('COD_NOT_COLLECTABLE', 409);
            $order = $this->orders->get($orderId) ?? throw new ApiException('NOT_FOUND', 404);
            $payment->update(['status' => 'refused']);
            DB::table('cod_refusals')->insertOrIgnore(['id' => (string) Str::uuid7(), 'user_id' => $order->userId, 'order_id' => $orderId, 'note' => $note, 'created_at' => now()]);
            $this->orders->recordCashRefused($orderId);

            return $payment;
        });
    }

    /** @return array{eligible: bool, reason: string|null} */
    public function codEligibility(string $userId): array
    {
        if (! config('gulfcart.cod.enabled')) {
            return ['eligible' => false, 'reason' => 'not_offered'];
        }
        $limit = (int) config('gulfcart.cod.block_after_refusals', 1);
        if ($limit > 0 && DB::table('cod_refusals')->where('user_id', $userId)->count() >= $limit) {
            return ['eligible' => false, 'reason' => 'refused_before'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    private function assertCodEligible(string $userId, ?string $orderMethod): void
    {
        $check = $this->codEligibility($userId);
        if (! $check['eligible']) {
            throw new ApiException('COD_NOT_AVAILABLE', 422, ['reason' => $check['reason']], (string) __('errors.cod_'.$check['reason']));
        }
        if ($orderMethod !== 'cod') {
            // The cash-on-delivery fee must be part of the order total: check out again choosing it.
            throw new ApiException('COD_NOT_AVAILABLE', 422, ['reason' => 'not_quoted'], (string) __('errors.cod_not_quoted'));
        }
    }

    private function markCodConfirmed(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment->update(['status' => 'pending_collection', 'confirmation_code_hash' => null]);
            $this->orders->confirmCashOnDelivery($payment->order_id);
        });
    }

    private function sendCodCode(Payment $payment, ?string $phone, string $countryCode, string $orderNumber, string $locale): void
    {
        $e164 = self::e164($phone, $countryCode) ?? throw new ApiException('COD_NO_PHONE', 422);
        $code = (string) random_int(100000, 999999);
        $payment->update(['confirmation_code_hash' => Hash::make($code), 'confirmation_attempts' => 0]);
        $this->sms->send($e164, $locale === 'ar'
            ? "رمز تأكيد طلب الدفع عند الاستلام {$orderNumber}: {$code}"
            : "GulfCart: your cash on delivery code for {$orderNumber} is {$code}");
    }

    /** Local numbers (05…) get the country prefix; +/00 numbers are kept. */
    public static function e164(?string $phone, string $countryCode): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '+')) {
            return $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }
        $prefix = ['AE' => '971', 'SA' => '966', 'KW' => '965', 'QA' => '974', 'BH' => '973', 'OM' => '968'][$countryCode] ?? null;

        return $prefix === null ? null : '+'.$prefix.ltrim($digits, '0');
    }

    /** @return string processed | duplicate | ignored | failed */
    public function handleWebhook(Request $request, ?string $provider = null): string
    {
        $gateway = $provider === null ? $this->gateways->default() : $this->gateways->get($provider);

        return $this->process($gateway->parseWebhook($request), $gateway->name());
    }

    /**
     * Finishes a payment when the customer returns from the provider page (PayPal captures here). The
     * same capture also arrives by webhook; the shared event ID makes the second one a duplicate.
     */
    public function confirm(string $paymentId): Payment
    {
        $payment = Payment::query()->findOrFail($paymentId);
        $gateway = $this->gateways->get($payment->provider);
        if ($gateway instanceof ConfirmsOnReturn && $payment->status === 'requires_action' && $payment->provider_reference !== null) {
            $event = $gateway->confirm($payment->provider_reference);
            if ($event !== null) {
                $this->process($event, $gateway->name());
            }
        }

        return $payment->refresh();
    }

    private function process(WebhookEvent $event, string $provider): string
    {
        if ($event->eventId === '' || $event->type === '') {
            throw new ApiException('BAD_REQUEST', 400);
        }

        return DB::transaction(function () use ($event, $provider): string {
            $inserted = PaymentWebhookEvent::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'provider' => $provider,
                'provider_event_id' => $event->eventId, 'event_type' => $event->type,
                'payload' => json_encode($event->payload), 'status' => 'received', 'received_at' => now(),
            ]);
            if ($inserted === 0) {
                return 'duplicate';
            }
            $row = PaymentWebhookEvent::query()->where('provider', $provider)->where('provider_event_id', $event->eventId)->firstOrFail();

            [$status, $error] = match ($event->type) {
                WebhookEvent::PAYMENT_CAPTURED => $this->captured($event, $provider),
                WebhookEvent::PAYMENT_FAILED => $this->failed($event, $provider),
                WebhookEvent::REFUND_SUCCEEDED, WebhookEvent::REFUND_FAILED => $this->refundOutcome($event),
                default => ['ignored', null],
            };
            $row->forceFill(['status' => $status, 'error' => $error, 'processed_at' => now()])->save();

            return $status;
        });
    }

    /** @return array{0: string, 1: string|null} */
    private function captured(WebhookEvent $e, string $provider): array
    {
        $payment = $this->lockByReference($provider, $e->providerReference);
        if ($payment === null) {
            return ['failed', 'Unknown payment reference.'];
        }
        if ($payment->status === 'captured') {
            return ['ignored', 'Already captured.'];
        }
        if ($e->amount === null || Decimal::cmp($e->amount, $payment->amount) !== 0 || $e->currency !== $payment->currency) {
            Log::critical('payments.amount_mismatch', ['payment_id' => $payment->id, 'expected' => $payment->amount.' '.$payment->currency, 'received' => $e->amount.' '.$e->currency]);

            return ['failed', 'Amount or currency mismatch; manual review required.'];
        }

        $payment->update(['status' => 'captured', 'paid_at' => now(), 'method' => $e->method]);
        $this->orders->recordPayment($payment->order_id, $payment->id, $payment->amount);
        Outbox::record('payment', $payment->id, 'payment.captured', ['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'amount' => $payment->amount, 'currency' => $payment->currency]);

        return ['processed', null];
    }

    /** @return array{0: string, 1: string|null} */
    private function failed(WebhookEvent $e, string $provider): array
    {
        $payment = $this->lockByReference($provider, $e->providerReference);
        if ($payment === null) {
            return ['failed', 'Unknown payment reference.'];
        }
        if ($payment->status === 'captured') {
            return ['ignored', 'Payment already captured.'];
        }
        $payment->update(['status' => 'failed', 'failure_code' => $e->failureCode]);
        $this->orders->recordPaymentFailure($payment->order_id);

        return ['processed', null];
    }

    /** @return array{0: string, 1: string|null} */
    private function refundOutcome(WebhookEvent $e): array
    {
        $refund = Refund::query()->where('provider_reference', $e->providerReference)->lockForUpdate()->first();
        if ($refund === null) {
            return ['failed', 'Unknown refund reference.'];
        }
        $this->refunds->finalize($refund, $e->type === WebhookEvent::REFUND_SUCCEEDED, $e->failureCode);

        return ['processed', null];
    }

    private function lockByReference(string $provider, string $reference): ?Payment
    {
        return Payment::query()->where('provider', $provider)->where('provider_reference', $reference)->lockForUpdate()->first();
    }
}
