<?php

namespace App\Modules\Orders\Contracts;

/** Implemented by Shipping: customer-safe tracking for an order. */
interface FulfillmentView
{
    /** @return list<array{shipment_group: int|null, carrier: string|null, tracking_number: string|null, tracking_url: string|null, status: string, events: list<array<string, mixed>>}> */
    public function trackingFor(string $orderId): array;
}
