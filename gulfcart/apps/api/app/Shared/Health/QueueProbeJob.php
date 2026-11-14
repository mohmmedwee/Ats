<?php

namespace App\Shared\Health;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Round-trip probe: proves a worker is consuming the queue (`php artisan app:queue-probe`). */
final class QueueProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        Cache::put('queue_probe:'.$this->token, now()->toIso8601String(), 300);
    }
}
