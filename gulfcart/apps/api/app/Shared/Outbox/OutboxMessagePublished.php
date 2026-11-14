<?php

namespace App\Shared\Outbox;

use Illuminate\Foundation\Events\Dispatchable;

/** Laravel event fired for each dispatched outbox row. Listeners must be idempotent. */
final class OutboxMessagePublished
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $outboxId,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly string $eventType,
        public readonly array $payload,
    ) {}
}
