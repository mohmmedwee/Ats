<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Application\FulfillmentProjector;
use App\Shared\Outbox\OutboxMessagePublished;

/** Reacts to Procurement/Shipping fulfilment events (contract: payload has order_id, order_item_ids, item_status). */
final class OrdersOutboxListener
{
    public function __construct(private readonly FulfillmentProjector $projector) {}

    public function handle(OutboxMessagePublished $event): void
    {
        if ($event->eventType !== 'fulfillment.items_updated') {
            return;
        }
        $this->projector->apply((string) $event->payload['order_id'], (array) $event->payload['order_item_ids'], (string) $event->payload['item_status']);
    }
}
