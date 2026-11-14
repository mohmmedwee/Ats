<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Procurement\Domain\SupplierOrderStatus;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;
use App\Shared\Outbox\Outbox;

/** Publishes supplier-order progress for Orders (item fulfilment) and Notifications. */
final class FulfillmentEvents
{
    public function statusChanged(SupplierOrder $so, string $from): void
    {
        Outbox::record('supplier_order', $so->id, 'supplier_order.status_changed', [
            'supplier_order_id' => $so->id, 'order_id' => $so->order_id, 'reference' => $so->reference,
            'from' => $from, 'to' => $so->status, 'exception_reason' => $so->exception_reason,
        ]);

        $itemStatus = SupplierOrderStatus::ITEM_STATUS[$so->status] ?? null;
        if ($itemStatus !== null) {
            Outbox::record('order', $so->order_id, 'fulfillment.items_updated', [
                'order_id' => $so->order_id,
                'order_item_ids' => $so->items()->pluck('order_item_id')->all(),
                'item_status' => $itemStatus,
                'shipment_group' => $so->shipment_group,
            ]);
        }
    }

    /** Validated transition + events in one call. Call inside a transaction. */
    public function move(SupplierOrder $so, string $to, array $attributes = []): void
    {
        $from = $so->status;
        $so->moveTo($to, $attributes);
        $this->statusChanged($so, $from);
    }
}
