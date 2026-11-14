<?php

namespace App\Modules\Returns\Application;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Orders\Contracts\OrderRefunder;
use App\Modules\Returns\Domain\ReturnStatus;
use App\Modules\Returns\Infrastructure\Models\ReturnItem;
use App\Modules\Returns\Infrastructure\Models\ReturnRequest;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer returns: delivered items only, within the country's return window, never more
 * than the quantity not yet refunded or reserved by another open return. Refund amount is the
 * unit price paid per returned unit; the refund goes through Payments (OrderRefunder).
 */
final class ReturnService
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly OrderRefunder $refunder,
        private readonly CountryDirectory $countries,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{order_item_id: string, quantity: int, reason_code: string}> $lines */
    public function request(string $userId, string $orderId, array $lines, string $reasonCode, ?string $note, ?Carbon $deliveredAt): ReturnRequest
    {
        return DB::transaction(function () use ($userId, $orderId, $lines, $reasonCode, $note, $deliveredAt) {
            $order = $this->orders->lockForFulfillment($orderId) ?? $this->orders->get($orderId);
            if ($order === null || $order->userId !== $userId) {
                throw new ApiException('NOT_FOUND', 404);
            }
            $window = (int) ($this->countries->find($order->countryCode)?->settings['returns']['window_days'] ?? config('gulfcart.returns.default_window_days', 14));
            if ($deliveredAt !== null && $deliveredAt->copy()->addDays($window)->isPast()) {
                throw new ApiException('RETURN_WINDOW_CLOSED', 422, ['window_days' => $window]);
            }

            $reserved = ReturnItem::query()->whereIn('return_id', ReturnRequest::query()->where('order_id', $orderId)->whereIn('status', ReturnStatus::OPEN)->select('id'))
                ->selectRaw('order_item_id, sum(quantity) as q')->groupBy('order_item_id')->pluck('q', 'order_item_id');

            $number = DB::selectOne("select nextval('rma_number_seq') as n")->n;
            $return = ReturnRequest::query()->create([
                'order_id' => $orderId, 'user_id' => $userId, 'rma_number' => 'RMA-'.$number, 'status' => 'requested',
                'reason_code' => $reasonCode, 'customer_note' => $note, 'currency' => $order->currency,
            ]);

            foreach ($lines as $i => $line) {
                $item = $order->item($line['order_item_id']);
                if ($item === null || $item->fulfillmentStatus !== 'delivered') {
                    throw new ApiException('ITEM_NOT_RETURNABLE', 422, ['line' => $i, 'order_item_id' => $line['order_item_id']]);
                }
                $available = $item->quantity - $item->refundedQuantity - (int) ($reserved[$item->id] ?? 0);
                if ($line['quantity'] > $available) {
                    throw new ApiException('ITEM_NOT_RETURNABLE', 422, ['line' => $i, 'available' => max(0, $available)]);
                }
                $return->items()->create([
                    'order_item_id' => $item->id, 'quantity' => $line['quantity'],
                    'reason_code' => $line['reason_code'], 'unit_refund' => $item->unitGross,
                ]);
            }

            Outbox::record('return', $return->id, 'return.requested', ['return_id' => $return->id, 'order_id' => $orderId, 'rma_number' => $return->rma_number]);

            return $return;
        });
    }

    public function transition(ReturnRequest $return, string $to, string $actorId, ?string $note = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $to, $actorId, $note) {
            $locked = ReturnRequest::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $locked->moveTo($to, ['handled_by' => $actorId, 'staff_note' => $note ?? $locked->staff_note]);
            $this->audit->record('return.'.$to, 'return', $locked->id, ['status' => $from], ['status' => $to, 'note' => $note], $actorId);
            Outbox::record('return', $locked->id, 'return.status_changed', ['return_id' => $locked->id, 'order_id' => $locked->order_id, 'from' => $from, 'to' => $to]);

            return $locked;
        });
    }

    /** Refund a received return (after inspection). */
    public function refund(ReturnRequest $return, string $actorId): ReturnRequest
    {
        return DB::transaction(function () use ($return, $actorId) {
            $locked = ReturnRequest::query()->with('items')->whereKey($return->id)->lockForUpdate()->firstOrFail();
            ReturnStatus::assertTransition($locked->status, 'refunded');

            $amount = '0';
            $quantities = [];
            foreach ($locked->items as $item) {
                $amount = Decimal::add($amount, Decimal::mul($item->unit_refund, (string) $item->quantity, 2), 2);
                $quantities[$item->order_item_id] = $item->quantity;
            }
            $refundId = $this->refunder->refund($locked->order_id, $amount, 'customer_return', null, $quantities, $locked->id);
            $locked->moveTo('refunded', ['refund_amount' => $amount, 'refund_id' => $refundId, 'handled_by' => $actorId]);
            Outbox::record('order', $locked->order_id, 'fulfillment.items_updated', [
                'order_id' => $locked->order_id, 'order_item_ids' => array_keys($quantities), 'item_status' => 'returned',
            ]);
            $this->audit->record('return.refunded', 'return', $locked->id, null, ['amount' => $amount, 'refund_id' => $refundId], $actorId);

            return $locked;
        });
    }
}
