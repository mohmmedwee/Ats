<?php

namespace App\Modules\Procurement\Providers;

use App\Modules\Orders\Contracts\CancellationPolicy;
use App\Modules\Procurement\Application\ProcurementCancellationPolicy;
use App\Modules\Procurement\Console\ProcurementReconcileCommand;
use App\Modules\Procurement\Console\ProcurementRunCommand;
use App\Modules\Procurement\Console\ProcurementTrackCommand;
use App\Modules\Procurement\Listeners\ProcurementOutboxListener;
use App\Shared\Modules\ModuleServiceProvider;
use App\Shared\Outbox\OutboxMessagePublished;
use Illuminate\Support\Facades\Event;

final class ProcurementServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Procurement';
    }

    public function register(): void
    {
        $this->app->singleton(CancellationPolicy::class, ProcurementCancellationPolicy::class);
    }

    public function boot(): void
    {
        parent::boot();
        Event::listen(OutboxMessagePublished::class, ProcurementOutboxListener::class);
        if ($this->app->runningInConsole()) {
            $this->commands([ProcurementRunCommand::class, ProcurementReconcileCommand::class, ProcurementTrackCommand::class]);
        }
    }
}
