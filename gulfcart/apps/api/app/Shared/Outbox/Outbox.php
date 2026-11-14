<?php

namespace App\Shared\Outbox;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Transactional outbox (ADR-010). Call record() inside the same database
 * transaction as the state change it describes.
 */
final class Outbox
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $aggregateType, string $aggregateId, string $eventType, array $payload): OutboxEvent
    {
        if (DB::transactionLevel() === 0 && ! app()->runningUnitTests()) {
            throw new LogicException('Outbox::record() must be called inside a database transaction.');
        }

        // Publish as soon as the surrounding transaction commits; the scheduler is the safety net.
        DB::afterCommit(static fn () => DispatchOutboxJob::dispatch());

        return OutboxEvent::query()->create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'pending',
            'available_at' => now(),
        ]);
    }
}
