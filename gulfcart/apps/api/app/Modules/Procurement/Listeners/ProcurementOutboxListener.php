<?php

namespace App\Modules\Procurement\Listeners;

use App\Modules\Procurement\Application\OrderSplitter;
use App\Shared\Outbox\OutboxMessagePublished;

final class ProcurementOutboxListener
{
    public function __construct(private readonly OrderSplitter $splitter) {}

    public function handle(OutboxMessagePublished $event): void
    {
        if ($event->eventType === 'order.paid') {
            $this->splitter->split((string) $event->payload['order_id']);
        }
    }
}
