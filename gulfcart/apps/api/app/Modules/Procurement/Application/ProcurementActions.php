<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Orders\Contracts\OrderRefunder;
use App\Modules\Procurement\Domain\SupplierOrderStatus as S;
use App\Modules\Procurement\Infrastructure\Models\ProcurementTask;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;
use App\Modules\Procurement\Jobs\ProcessProcurementTask;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Contracts\SourcingDirectory;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/** Operator actions on the procurement exception queue (docs/PROCUREMENT_WORKFLOW.md §7). Every action is audited. */
final class ProcurementActions
{
    public function __construct(
        private readonly FulfillmentEvents $events,
        private readonly OrderBook $orders,
        private readonly OrderRefunder $refunder,
        private readonly SourcingDirectory $sourcing,
        private readonly ConnectorResolver $connectors,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Retry an exception. With $newKey the operator confirms the supplier has no order for the
     * previous key (required after UNKNOWN_OUTCOME_UNRESOLVED); a new key version is issued.
     */
    public function retry(ProcurementTask $task, string $actorId, bool $newKey): void
    {
        DB::transaction(function () use ($task, $actorId, $newKey) {
            $so = $this->lockSo($task);
            if ($so->status !== S::EXCEPTION) {
                throw new ApiException('ILLEGAL_STATE_TRANSITION', 409, ['entity' => 'supplier_order', 'from' => $so->status, 'to' => S::PENDING]);
            }
            if ($so->exception_reason === 'UNKNOWN_OUTCOME_UNRESOLVED' && ! $newKey) {
                throw new ApiException('NEW_IDEMPOTENCY_KEY_REQUIRED', 422, [], 'Confirm with the supplier that no order exists, then retry with new_idempotency_key=true.');
            }
            $attributes = ['exception_reason' => null, 'exception_details' => null];
            if ($newKey) {
                $version = $so->key_version + 1;
                $attributes += ['key_version' => $version, 'idempotency_key' => preg_replace('/_v\d+$/', '', $so->idempotency_key).'_v'.$version];
            }
            $this->events->move($so, S::PENDING, $attributes);
            $task->update(['status' => 'queued', 'next_attempt_at' => now(), 'attempts' => 0, 'last_error' => null]);
            $this->audit->record('procurement.retry', 'supplier_order', $so->id, null, ['new_key' => $newKey], $actorId);
        });
        ProcessProcurementTask::dispatch($task->id)->afterCommit();
    }

    /** Record a purchase made by an operator outside the API (manual suppliers or exceptions). */
    public function approveManual(ProcurementTask $task, string $actorId, string $externalOrderId, string $actualCost): void
    {
        DB::transaction(function () use ($task, $actorId, $externalOrderId, $actualCost) {
            $so = $this->lockSo($task);
            if (! in_array($so->status, [S::MANUAL, S::EXCEPTION, S::UNKNOWN], true)) {
                throw new ApiException('ILLEGAL_STATE_TRANSITION', 409, ['entity' => 'supplier_order', 'from' => $so->status, 'to' => S::PURCHASED]);
            }
            $this->events->move($so, S::PURCHASED, [
                'external_order_id' => $externalOrderId, 'actual_cost' => $actualCost, 'purchased_at' => now(),
                'procurement_mode' => 'manual', 'exception_reason' => null, 'exception_details' => null,
                'supplier_response' => ['recorded_by' => $actorId, 'manual' => true],
            ]);
            $task->update(['status' => 'succeeded', 'last_error' => null, 'assigned_to' => $actorId]);
            $this->audit->record('procurement.manual_purchase_recorded', 'supplier_order', $so->id, null, ['external_order_id' => $externalOrderId, 'actual_cost' => $actualCost], $actorId);
        });
    }

    /** Move the items to another eligible supplier; the old supplier order is cancelled. */
    public function switchSource(ProcurementTask $task, string $actorId, string $targetSupplierProductId): string
    {
        $newTask = DB::transaction(function () use ($task, $actorId, $targetSupplierProductId) {
            $so = $this->lockSo($task);
            if (! in_array($so->status, [S::EXCEPTION, S::MANUAL], true)) {
                throw new ApiException('ILLEGAL_STATE_TRANSITION', 409, ['entity' => 'supplier_order', 'from' => $so->status, 'to' => 'switch_source']);
            }
            $order = $this->orders->get($so->order_id) ?? throw new ApiException('NOT_FOUND', 404);
            $country = (string) ($so->shipping_address['country'] ?? '');

            $sources = [];
            foreach ($so->items()->get() as $item) {
                $variantId = (string) $order->item($item->order_item_id)?->variantId;
                $match = array_values(array_filter($this->sourcing->sourcesFor($variantId, $country), fn ($s) => $s->supplierProductId === $targetSupplierProductId));
                if ($match === []) {
                    throw new ApiException('SOURCE_NOT_ELIGIBLE', 422, ['order_item_id' => $item->order_item_id]);
                }
                $sources[$item->id] = [$item, $match[0]];
            }
            $targetSupplier = reset($sources)[1]->supplierId;
            if ($targetSupplier === $so->supplier_id) {
                throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['supplier_product_id' => ['Choose a different supplier.']]]);
            }
            if (SupplierOrder::query()->where('order_id', $so->order_id)->where('supplier_id', $targetSupplier)->where('status', '<>', S::CANCELLED)->exists()) {
                throw new ApiException('CONFLICT', 409, [], 'The order already has a live supplier order with that supplier.');
            }

            $number = DB::selectOne("select nextval('supplier_order_number_seq') as n")->n;
            $new = SupplierOrder::query()->create([
                'reference' => 'SO-'.$number, 'order_id' => $so->order_id, 'supplier_id' => $targetSupplier,
                'shipment_group' => $so->shipment_group, 'status' => S::PENDING, 'idempotency_key' => 'so_'.$number.'_v1',
                'expected_cost' => '0', 'cost_currency' => reset($sources)[1]->costCurrency, 'exchange_rate' => $so->exchange_rate,
                'shipping_address' => $so->shipping_address,
            ]);
            $expected = '0';
            foreach ($sources as [$item, $source]) {
                $new->items()->create([
                    'order_item_id' => $item->order_item_id, 'supplier_product_id' => $source->supplierProductId,
                    'external_product_id' => $source->externalProductId, 'external_variant_id' => $source->externalVariantId,
                    'purchase_quantity' => $item->purchase_quantity, 'purchase_cost' => $source->unitCost,
                    'shipping_cost' => $source->shippingCost, 'currency' => $source->costCurrency,
                ]);
                $expected = Decimal::add($expected, Decimal::mul(Decimal::add($source->unitCost, $source->shippingCost), (string) $item->purchase_quantity));
            }
            $new->update(['expected_cost' => $expected]);

            $from = $so->status;
            $so->moveTo(S::CANCELLED, ['exception_details' => ($so->exception_details ?? []) + ['replaced_by' => $new->reference]]);
            // Items stay with the order (not cancelled): only emit the supplier-order event.
            Outbox::record('supplier_order', $so->id, 'supplier_order.status_changed', ['supplier_order_id' => $so->id, 'order_id' => $so->order_id, 'from' => $from, 'to' => S::CANCELLED, 'replaced_by' => $new->id]);
            $task->update(['status' => 'cancelled']);
            $newTask = ProcurementTask::query()->create(['supplier_order_id' => $new->id, 'status' => 'queued', 'next_attempt_at' => now()]);
            $this->audit->record('procurement.source_switched', 'supplier_order', $so->id, null, ['new_supplier_order' => $new->reference, 'supplier_product_id' => $targetSupplierProductId], $actorId);

            return $newTask;
        });
        ProcessProcurementTask::dispatch($newTask->id)->afterCommit();

        return $newTask->id;
    }

    /**
     * Cancel a supplier order that has not been purchased (or that the supplier agrees to cancel)
     * and optionally refund the customer for its items.
     */
    public function cancel(ProcurementTask $task, string $actorId, bool $refund): void
    {
        DB::transaction(function () use ($task, $actorId, $refund) {
            $so = $this->lockSo($task);
            if (in_array($so->status, [S::PURCHASED, S::SUPPLIER_PROCESSING], true)) {
                $result = $this->connectors->forSupplier($so->supplier_id)->cancelOrder((string) $so->external_order_id);
                if (! $result->cancelled) {
                    throw new ApiException('SUPPLIER_CANCELLATION_REFUSED', 409, ['reason' => $result->reason]);
                }
            } elseif (in_array($so->status, S::COMMITTED, true)) {
                throw new ApiException('ILLEGAL_STATE_TRANSITION', 409, ['entity' => 'supplier_order', 'from' => $so->status, 'to' => S::CANCELLED]);
            }
            $this->events->move($so, S::CANCELLED);
            $task->update(['status' => 'cancelled']);

            if ($refund) {
                $order = $this->orders->get($so->order_id) ?? throw new ApiException('NOT_FOUND', 404);
                $items = [];
                $amount = '0';
                foreach ($so->items()->get() as $soi) {
                    $line = $order->item($soi->order_item_id);
                    $qty = ($line?->quantity ?? 0) - ($line?->refundedQuantity ?? 0);
                    if ($line !== null && $qty > 0) {
                        $items[$line->id] = $qty;
                        $amount = Decimal::add($amount, Decimal::mul($line->unitGross, (string) $qty, 2), 2);
                    }
                }
                // When nothing else remains to fulfil, refund everything left (shipping, customs).
                $others = SupplierOrder::query()->where('order_id', $so->order_id)->whereKeyNot($so->id)->where('status', '<>', S::CANCELLED)->exists();
                if (! $others) {
                    $amount = Decimal::sub($order->paidTotal, $order->refundedTotal, 2);
                }
                if (Decimal::cmp($amount, '0') > 0) {
                    $this->refunder->refund($so->order_id, $amount, 'procurement_failed', null, $items);
                }
            }
            $this->audit->record('procurement.cancelled', 'supplier_order', $so->id, null, ['refund' => $refund], $actorId);
        });
    }

    private function lockSo(ProcurementTask $task): SupplierOrder
    {
        return SupplierOrder::query()->whereKey($task->supplier_order_id)->lockForUpdate()->firstOrFail();
    }
}
