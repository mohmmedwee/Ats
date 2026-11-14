<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => DB::table('idempotency_keys')->where('expires_at', '<', now())->delete())
    ->name('idempotency:prune')->hourly();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('fx:refresh --reprice')->hourly()->withoutOverlapping();
Schedule::command('suppliers:sync')->everyThirtyMinutes()->withoutOverlapping();
