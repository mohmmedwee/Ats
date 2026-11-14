<?php

namespace App\Shared\Outbox;

use Illuminate\Console\Command;

/** Safety net (scheduled every minute) for events whose after-commit dispatch was lost. */
final class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100 : Maximum events per run}';

    protected $description = 'Publish pending transactional outbox events';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $this->info('Dispatched '.$dispatcher->dispatch(max(1, (int) $this->option('limit'))).' event(s).');

        return self::SUCCESS;
    }
}
