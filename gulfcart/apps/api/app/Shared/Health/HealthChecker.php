<?php

namespace App\Shared\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Readiness checks for PostgreSQL, Redis and the queue connection. */
final class HealthChecker
{
    /**
     * @return array{status: 'ok'|'down', checks: array<string, 'ok'|'down'>}
     */
    public function ready(): array
    {
        $checks = [
            'postgres' => $this->check('postgres', fn () => DB::select('select 1')),
            'redis' => $this->check('redis', fn () => Redis::connection()->ping()),
            'queue' => $this->check('queue', fn () => Queue::connection()->size()),
        ];

        $ok = ! in_array('down', $checks, true);

        return ['status' => $ok ? 'ok' : 'down', 'checks' => $checks];
    }

    /**
     * @return 'ok'|'down'
     */
    private function check(string $name, callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            Log::warning('health.check_failed', ['check' => $name, 'error' => $e::class]);

            return 'down';
        }
    }
}
