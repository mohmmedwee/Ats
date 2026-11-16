<?php

namespace App\Modules\Engagement\Providers;

use App\Modules\Engagement\Console\CheckProductAlertsCommand;
use App\Shared\Modules\ModuleServiceProvider;

final class EngagementServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Engagement';
    }

    public function boot(): void
    {
        parent::boot();
        if ($this->app->runningInConsole()) {
            $this->commands([CheckProductAlertsCommand::class]);
        }
    }
}
