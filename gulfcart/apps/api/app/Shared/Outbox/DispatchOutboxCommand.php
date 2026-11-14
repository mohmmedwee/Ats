<?php

namespace App\Shared\Outbox;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100 : Maximum events per run}';

    protected $description = 'Publish pending transactional outbox events';

    private const MAX_ATTEMPTS = 10;

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $dispatched = DB::transaction(function () use ($limit): int {
            $rows = OutboxEvent::query()
                ->where('status', 'pending')
                ->where('available_at', '<=', now())
                ->orderBy('created_at')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            $count = 0;
            foreach ($rows as $row) {
                try {
                    event(new OutboxMessagePublished($row->id, $row->aggregate_type, $row->aggregate_id, $row->event_type, $row->payload));
                    $row->forceFill(['status' => 'dispatched', 'dispatched_at' => now(), 'last_error' => null])->save();
                    $count++;
                } catch (Throwable $e) {
                    $attempts = $row->attempts + 1;
                    $row->forceFill([
                        'attempts' => $attempts,
                        'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                        'available_at' => now()->addSeconds(min(3600, 2 ** $attempts)),
                        'last_error' => mb_substr($e->getMessage(), 0, 2000),
                    ])->save();
                    Log::warning('outbox.dispatch_failed', ['outbox_id' => $row->id, 'event_type' => $row->event_type, 'attempts' => $attempts]);
                }
            }

            return $count;
        });

        $this->info("Dispatched {$dispatched} event(s).");

        return self::SUCCESS;
    }
}
