<?php

namespace App\Modules\Shipping\Application;

use App\Modules\Orders\Contracts\FulfillmentView;
use App\Modules\Shipping\Contracts\ShipmentRecorder;
use App\Modules\Shipping\Domain\ShipmentUpdate;
use App\Modules\Shipping\Infrastructure\Models\Shipment;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShipmentService implements FulfillmentView, ShipmentRecorder
{
    private const STATUS = [
        'processing' => 'label_created', 'shipped' => 'in_transit', 'in_transit' => 'in_transit', 'customs' => 'customs',
        'out_for_delivery' => 'out_for_delivery', 'delivered' => 'delivered', 'exception' => 'exception', 'returned' => 'returned',
    ];

    public function record(ShipmentUpdate $u): string
    {
        return DB::transaction(function () use ($u): string {
            $status = self::STATUS[$u->status] ?? 'in_transit';
            $shipment = Shipment::query()->where('supplier_order_id', $u->supplierOrderId)->where('tracking_number', $u->trackingNumber)->lockForUpdate()->first();
            $isNew = $shipment === null;

            if ($isNew) {
                $shipment = Shipment::query()->create([
                    'order_id' => $u->orderId, 'supplier_order_id' => $u->supplierOrderId, 'shipment_group' => $u->shipmentGroup,
                    'carrier' => $u->carrier, 'tracking_number' => $u->trackingNumber, 'tracking_url' => $u->trackingUrl,
                    'status' => $status, 'shipped_at' => now(),
                ]);
                foreach ($u->itemQuantities as $itemId => $qty) {
                    DB::table('shipment_items')->insert(['shipment_id' => $shipment->id, 'order_item_id' => $itemId, 'quantity' => $qty]);
                }
            } elseif ($shipment->status !== $status && $shipment->status !== 'delivered') {
                $shipment->update(['status' => $status, 'tracking_url' => $u->trackingUrl ?? $shipment->tracking_url]);
            }
            if ($status === 'delivered' && $shipment->delivered_at === null) {
                $shipment->update(['delivered_at' => now()]);
            }

            foreach ($u->events as $e) {
                DB::table('shipment_events')->insertOrIgnore([
                    'id' => (string) Str::uuid7(), 'shipment_id' => $shipment->id,
                    'event_code' => mb_substr((string) $e['code'], 0, 100), 'description' => $e['description'] ?? null,
                    'event_location' => $e['location'] ?? null, 'occurred_at' => $e['occurred_at'], 'created_at' => now(),
                ]);
            }

            if ($isNew || $shipment->wasChanged('status')) {
                Outbox::record('shipment', $shipment->id, 'shipment.updated', [
                    'shipment_id' => $shipment->id, 'order_id' => $u->orderId, 'status' => $shipment->status,
                    'carrier' => $shipment->carrier, 'tracking_number' => $shipment->tracking_number, 'new' => $isNew,
                ]);
            }

            return $shipment->id;
        });
    }

    public function trackingFor(string $orderId): array
    {
        return Shipment::query()->with('events')->where('order_id', $orderId)->orderBy('shipment_group')->get()
            ->map(fn (Shipment $s) => [
                'shipment_group' => $s->shipment_group,
                'carrier' => $s->carrier,
                'tracking_number' => $s->tracking_number,
                'tracking_url' => $s->tracking_url,
                'status' => $s->status,
                'shipped_at' => $s->shipped_at?->toIso8601String(),
                'delivered_at' => $s->delivered_at?->toIso8601String(),
                'events' => $s->events->map(fn ($e) => [
                    'code' => $e->event_code, 'description' => $e->description, 'location' => $e->event_location,
                    'occurred_at' => $e->occurred_at->toIso8601String(),
                ])->all(),
            ])->all();
    }
}
