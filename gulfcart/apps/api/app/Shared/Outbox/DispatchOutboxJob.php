<?php

namespace App\Shared\Outbox;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Low-latency dispatch right after a transaction that recorded outbox events commits. */
final class DispatchOutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('outbox');
    }

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $dispatcher->dispatch();
    }
}
