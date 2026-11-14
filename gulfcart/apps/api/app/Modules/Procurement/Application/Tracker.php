<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Procurement\Domain\SupplierOrderStatus as S;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;
use App\Modules\Shipping\Contracts\ShipmentRecorder;
use App\Modules\Shipping\Domain\ShipmentUpdate;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Pulls supplier status and tracking for purchased supplier orders and records shipments. */
final class Tracker
{
    private const MAP = ['processing' => S::SUPPLIER_PROCESSING, 'shipped' => S::SHIPPED, 'delivered' => S::DELIVERED, 'cancelled' => S::CANCELLED];

    public function __construct(
        private readonly ConnectorResolver $connectors,
        private readonly ShipmentRecorder $shipments,
        private readonly FulfillmentEvents $events,
    ) {}

    public function track(SupplierOrder $so): ?string
    {
        if ($so->external_order_id === null || ! in_array($so->status, [S::PURCHASED, S::SUPPLIER_PROCESSING, S::SHIPPED, S::CANCEL_REQUESTED], true)) {
            return null;
        }
        $connector = $this->connectors->forSupplier($so->supplier_id);
        if (! $connector->capabilities()->tracking) {
            return null;
        }

        try {
            $status = $connector->getOrderStatus($so->external_order_id);
            $tracking = $connector->getTracking($so->external_order_id);
        } catch (ConnectorException $e) {
            Log::info('procurement.tracking_failed', ['supplier_order' => $so->reference, 'error' => $e->getMessage()]);

            return null;
        }

        $target = self::MAP[$status->status] ?? null;

        return DB::transaction(function () use ($so, $target, $tracking) {
            $locked = SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $locked->update(['last_tracked_at' => now()]);

            if ($tracking->trackingNumber !== null) {
                $this->shipments->record(new ShipmentUpdate(
                    $locked->order_id, $locked->id, $locked->shipment_group,
                    $locked->items()->pluck('purchase_quantity', 'order_item_id')->map(fn ($q) => (int) $q)->all(),
                    $tracking->carrier, $tracking->trackingNumber, $tracking->trackingUrl, $tracking->status, $tracking->events,
                ));
            }

            if ($target !== null && $target !== $locked->status && S::canTransition($locked->status, $target)) {
                $this->events->move($locked, $target);
                if ($target === S::CANCELLED) {
                    // Supplier cancelled after purchase: needs an operator decision (re-source or refund).
                    Outbox::record('supplier_order', $locked->id, 'procurement.exception', ['supplier_order_id' => $locked->id, 'order_id' => $locked->order_id, 'reason' => 'SUPPLIER_CANCELLED']);
                    Log::warning('procurement.supplier_cancelled', ['supplier_order' => $locked->reference]);
                }

                return $target;
            }

            return null;
        });
    }
}
