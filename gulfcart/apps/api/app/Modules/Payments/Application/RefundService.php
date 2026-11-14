<?php

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Orders\Contracts\OrderRefunder;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refund lifecycle: requested → approved → processing → succeeded | failed (or rejected).
 * The sum of succeeded and in-flight refunds can never exceed what was captured; this is
 * checked under a row lock on the order's payments (and by DB constraints).
 * System refunds and staff refunds up to the threshold by an approver are auto-approved;
 * larger staff refunds need a second, different approver (four-eyes).
 */
final class RefundService implements OrderRefunder
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderBook $orders,
        private readonly AuditLogger $audit,
    ) {}

    public function refund(string $orderId, string $amount, string $reasonCode, ?string $actorId, array $itemQuantities = [], ?string $returnId = null): string
    {
        return $this->request($orderId, $amount, $reasonCode, null, $actorId, $actorId === null, $itemQuantities, $returnId)[0]->id;
    }

    /**
     * @param  array<string, int>  $itemQuantities
     * @return list<Refund>
     */
    public function request(string $orderId, string $amount, string $reasonCode, ?string $reason, ?string $actorId, bool $actorCanApprove, array $itemQuantities = [], ?string $returnId = null): array
    {
        if (Decimal::cmp($amount, '0') <= 0) {
            throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['amount' => ['Amount must be positive.']]]);
        }

        $refunds = DB::transaction(function () use ($orderId, $amount, $reasonCode, $reason, $actorId, $actorCanApprove, $itemQuantities, $returnId): array {
            $payments = Payment::query()->where('order_id', $orderId)->where('status', 'captured')->orderBy('paid_at')->lockForUpdate()->get();
            $inFlight = Refund::query()->where('order_id', $orderId)->whereIn('status', ['requested', 'approved', 'processing'])->get()->groupBy('payment_id');

            $remaining = $amount;
            $created = [];
            $autoApprove = $actorId === null || ($actorCanApprove && Decimal::cmp($amount, $this->threshold($payments->first()?->currency)) <= 0);

            foreach ($payments as $payment) {
                $pending = Decimal::sum($inFlight->get($payment->id, collect())->pluck('amount')->map(fn ($a) => (string) $a)->all(), 2);
                $available = Decimal::sub(Decimal::sub($payment->amount, $payment->refunded_amount, 2), $pending, 2);
                if (Decimal::cmp($available, '0') <= 0 || Decimal::cmp($remaining, '0') <= 0) {
                    continue;
                }
                $part = Decimal::cmp($remaining, $available) <= 0 ? $remaining : $available;
                $created[] = Refund::query()->create([
                    'order_id' => $orderId, 'payment_id' => $payment->id, 'return_id' => $returnId,
                    'amount' => $part, 'currency' => $payment->currency, 'reason_code' => $reasonCode, 'reason' => $reason,
                    'item_quantities' => $created === [] ? $itemQuantities : [],
                    'status' => $autoApprove ? 'approved' : 'requested',
                    'idempotency_key' => 'rf_'.Str::lower((string) Str::ulid()),
                    'requested_by' => $actorId, 'approved_by' => $autoApprove ? $actorId : null,
                ]);
                $remaining = Decimal::sub($remaining, $part, 2);
            }

            if (Decimal::cmp($remaining, '0') > 0) {
                throw new ApiException('REFUND_EXCEEDS_CAPTURED', 422, ['requested' => $amount]);
            }
            foreach ($created as $r) {
                $this->audit->record('refund.requested', 'refund', $r->id, null, ['order_id' => $orderId, 'amount' => $r->amount, 'reason_code' => $reasonCode, 'status' => $r->status], $actorId);
            }

            return $created;
        });

        foreach ($refunds as $refund) {
            if ($refund->status === 'approved') {
                $this->execute($refund->id);
            }
        }

        return array_map(fn (Refund $r) => $r->refresh(), $refunds);
    }

    public function approve(string $refundId, string $approverId): Refund
    {
        DB::transaction(function () use ($refundId, $approverId) {
            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'requested') {
                throw new ApiException('ILLEGAL_STATE_TRANSITION', 409, ['entity' => 'refund', 'from' => $refund->status, 'to' => 'approved']);
            }
            if ($refund->requested_by === $approverId) {
                throw new ApiException('FOUR_EYES_REQUIRED', 403);
            }
            $refund->update(['status' => 'approved', 'approved_by' => $approverId]);
            $this->audit->record('refund.approved', 'refund', $refund->id, null, ['amount' => $refund->amount], $approverId);
        });
        $this->execute($refundId);

        return Refund::query()->findOrFail($refundId);
    }

    public function reject(string $refundId, string $approverId, ?string $reason): Refund
    {
        return DB::transaction(function () use ($refundId, $approverId, $reason) {
            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'requested') {
                throw new ApiException('ILLEGAL_STATE_TRANSITION', 409, ['entity' => 'refund', 'from' => $refund->status, 'to' => 'rejected']);
            }
            $refund->update(['status' => 'rejected', 'approved_by' => $approverId, 'failure_reason' => $reason]);
            $this->audit->record('refund.rejected', 'refund', $refund->id, null, ['reason' => $reason], $approverId);

            return $refund;
        });
    }

    /** Sends an approved refund to the gateway. Safe to call twice (idempotency key + status lock). */
    public function execute(string $refundId): void
    {
        $refund = DB::transaction(function () use ($refundId) {
            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->first();
            if ($refund === null || $refund->status !== 'approved') {
                return null;
            }
            $refund->update(['status' => 'processing']);

            return $refund;
        });
        if ($refund === null) {
            return;
        }

        $payment = Payment::query()->findOrFail($refund->payment_id);
        try {
            $result = $this->gateway->refund((string) $payment->provider_reference, $refund->amount, $refund->currency, $refund->idempotency_key);
        } catch (Throwable $e) {
            Log::error('refunds.gateway_error', ['refund_id' => $refund->id, 'error' => $e->getMessage()]);

            // Outcome unknown: stay in processing; the provider webhook (or an operator) finalizes it.
            return;
        }

        DB::transaction(function () use ($refund, $result) {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $locked->update(['provider_reference' => $result->providerReference]);
            if ($result->status !== 'pending') {
                $this->finalize($locked, $result->status === 'succeeded', $result->failureReason);
            }
        });
    }

    /** Applies the final outcome. Must run inside a transaction with the refund row locked. */
    public function finalize(Refund $refund, bool $succeeded, ?string $failure): void
    {
        if (in_array($refund->status, ['succeeded', 'failed'], true)) {
            return;
        }
        if (! $succeeded) {
            $refund->update(['status' => 'failed', 'failure_reason' => $failure]);
            Log::error('refunds.failed', ['refund_id' => $refund->id, 'reason' => $failure]);

            return;
        }

        $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
        $payment->update(['refunded_amount' => Decimal::add($payment->refunded_amount, $refund->amount, 2)]);
        $refund->update(['status' => 'succeeded']);
        $this->orders->recordRefund($refund->order_id, $refund->amount, $refund->item_quantities);
        Outbox::record('refund', $refund->id, 'refund.succeeded', ['refund_id' => $refund->id, 'order_id' => $refund->order_id, 'amount' => $refund->amount, 'currency' => $refund->currency, 'reason_code' => $refund->reason_code]);
    }

    private function threshold(?string $currency): string
    {
        return (string) (config('gulfcart.payments.refund_approval_threshold')[$currency ?? 'AED'] ?? '500');
    }
}
