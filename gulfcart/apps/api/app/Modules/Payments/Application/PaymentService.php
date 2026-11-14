<?php

namespace App\Modules\Payments\Application;

use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Payments\Contracts\PaymentGateway;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderBook $orders,
        private readonly RefundService $refunds,
    ) {}

    public function createIntent(string $userId, string $orderId, string $idempotencyKey): Payment
    {
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

        $open = Payment::query()->where('order_id', $orderId)->whereIn('status', ['created', 'requires_action'])
            ->where('amount', $due)->latest()->first();
        if ($open !== null) {
            return $open; // reuse the open intent instead of creating parallel ones
        }

        $payment = Payment::query()->create([
            'order_id' => $orderId, 'provider' => $this->gateway->name(), 'idempotency_key' => 'pay_'.$idempotencyKey,
            'amount' => $due, 'currency' => $order->currency, 'status' => 'created',
        ]);

        $returnUrl = rtrim((string) config('gulfcart.frontend_url'), '/').($order->locale === 'ar' ? '/ar' : '').'/payment/return';
        $result = $this->gateway->createIntent(new IntentRequest($payment->id, $due, $order->currency, $order->orderNumber, $returnUrl), $payment->idempotency_key);
        $payment->update(['provider_reference' => $result->providerReference, 'status' => $result->status, 'redirect_url' => $result->redirectUrl]);

        return $payment;
    }

    /** @return string processed | duplicate | ignored | failed */
    public function handleWebhook(Request $request): string
    {
        $event = $this->gateway->parseWebhook($request);
        if ($event->eventId === '' || $event->type === '') {
            throw new ApiException('BAD_REQUEST', 400);
        }

        return DB::transaction(function () use ($event): string {
            $inserted = PaymentWebhookEvent::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'provider' => $this->gateway->name(),
                'provider_event_id' => $event->eventId, 'event_type' => $event->type,
                'payload' => json_encode($event->payload), 'status' => 'received', 'received_at' => now(),
            ]);
            if ($inserted === 0) {
                return 'duplicate';
            }
            $row = PaymentWebhookEvent::query()->where('provider', $this->gateway->name())->where('provider_event_id', $event->eventId)->firstOrFail();

            [$status, $error] = match ($event->type) {
                WebhookEvent::PAYMENT_CAPTURED => $this->captured($event),
                WebhookEvent::PAYMENT_FAILED => $this->failed($event),
                WebhookEvent::REFUND_SUCCEEDED, WebhookEvent::REFUND_FAILED => $this->refundOutcome($event),
                default => ['ignored', null],
            };
            $row->forceFill(['status' => $status, 'error' => $error, 'processed_at' => now()])->save();

            return $status;
        });
    }

    /** @return array{0: string, 1: string|null} */
    private function captured(WebhookEvent $e): array
    {
        $payment = $this->lockByReference($e->providerReference);
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
    private function failed(WebhookEvent $e): array
    {
        $payment = $this->lockByReference($e->providerReference);
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

    private function lockByReference(string $reference): ?Payment
    {
        return Payment::query()->where('provider', $this->gateway->name())->where('provider_reference', $reference)->lockForUpdate()->first();
    }
}
