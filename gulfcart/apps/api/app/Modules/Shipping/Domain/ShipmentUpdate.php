<?php

namespace App\Modules\Shipping\Domain;

final class ShipmentUpdate
{
    /**
     * @param  array<string, int>  $itemQuantities  order item ID => quantity
     * @param  list<array{code: string, description: string, location: string|null, occurred_at: string}>  $events
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $supplierOrderId,
        public readonly int $shipmentGroup,
        public readonly array $itemQuantities,
        public readonly ?string $carrier,
        public readonly ?string $trackingNumber,
        public readonly ?string $trackingUrl,
        public readonly string $status,
        public readonly array $events,
    ) {}
}
