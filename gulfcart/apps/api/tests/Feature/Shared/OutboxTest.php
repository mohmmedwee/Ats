<?php

namespace Tests\Feature\Shared;

use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxMessagePublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

final class OutboxTest extends TestCase
{
    public function test_pending_events_are_published_once(): void
    {
        $received = [];
        Event::listen(OutboxMessagePublished::class, function (OutboxMessagePublished $e) use (&$received) {
            $received[] = $e->eventType;
        });

        $id = (string) \Str::uuid7();
        DB::transaction(fn () => Outbox::record('order', $id, 'OrderCreated', ['order_number' => 'GC-10001']));

        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->artisan('outbox:dispatch')->assertSuccessful();

        $this->assertSame(['OrderCreated'], $received);
        $this->assertSame('dispatched', OutboxEvent::query()->sole()->status);
    }

    public function test_a_failing_listener_schedules_a_retry(): void
    {
        Event::listen(OutboxMessagePublished::class, fn () => throw new RuntimeException('downstream unavailable'));
        DB::transaction(fn () => Outbox::record('order', (string) \Str::uuid7(), 'OrderPaid', []));

        $this->artisan('outbox:dispatch')->assertSuccessful();

        $event = OutboxEvent::query()->sole();
        $this->assertSame('pending', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertTrue($event->available_at->isFuture());
        $this->assertSame('downstream unavailable', $event->last_error);
    }
}
