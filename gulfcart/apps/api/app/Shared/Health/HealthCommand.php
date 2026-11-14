<?php

namespace App\Shared\Health;

use Illuminate\Console\Command;

/** Used by the Docker health check: `php artisan app:health --ready`. */
final class HealthCommand extends Command
{
    protected $signature = 'app:health {--ready : Check PostgreSQL, Redis and the queue}';

    protected $description = 'Report application health';

    public function handle(HealthChecker $checker): int
    {
        if (! $this->option('ready')) {
            $this->line('ok');

            return self::SUCCESS;
        }

        $result = $checker->ready();
        foreach ($result['checks'] as $name => $state) {
            $this->line("{$name}: {$state}");
        }

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
