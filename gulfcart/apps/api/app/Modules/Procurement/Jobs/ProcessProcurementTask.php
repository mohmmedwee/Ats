<?php

namespace App\Modules\Procurement\Jobs;

use App\Modules\Procurement\Application\ProcurementEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Never retried by the queue: the engine owns retry policy, and a crashed run is picked up by
 * `procurement:run` / reconciliation instead (a blind queue retry could double-purchase).
 */
final class ProcessProcurementTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $taskId)
    {
        $this->onQueue('procurement');
    }

    public function handle(ProcurementEngine $engine): void
    {
        $engine->process($this->taskId);
    }
}
