<?php

namespace App\Shared\Outbox;

use Illuminate\Console\Command;

/** Long-running dispatcher: ~1 s latency without relying on the scheduler (Docker service `outbox`). */
final class OutboxWorkCommand extends Command
{
    protected $signature = 'outbox:work {--sleep=1 : Seconds to sleep when idle} {--max-time=3600 : Exit after N seconds (supervisor restarts it)}';

    protected $description = 'Continuously publish pending outbox events';

    private bool $stop = false;

    public function handle(OutboxDispatcher $dispatcher): int
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }
        $deadline = time() + max(1, (int) $this->option('max-time'));

        while (! $this->stop && time() < $deadline) {
            if ($dispatcher->dispatch() === 0) {
                usleep(max(100, (int) ((float) $this->option('sleep') * 1_000_000)));
            }
        }

        return self::SUCCESS;
    }
}
