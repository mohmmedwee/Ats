<?php

namespace App\Shared\Health;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class QueueProbeCommand extends Command
{
    protected $signature = 'app:queue-probe {--wait=10 : Seconds to wait for a worker}';

    protected $description = 'Dispatch a probe job and wait until a worker has processed it';

    public function handle(): int
    {
        $token = (string) Str::uuid();
        QueueProbeJob::dispatch($token);

        $deadline = microtime(true) + (int) $this->option('wait');
        do {
            if (Cache::has('queue_probe:'.$token)) {
                $this->info('Queue probe processed by a worker.');

                return self::SUCCESS;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        $this->error('Queue probe was not processed in time.');

        return self::FAILURE;
    }
}
