<?php

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Application\DatabaseAuditLogger;
use App\Modules\Audit\Contracts\AuditLogger;
use App\Shared\Modules\ModuleServiceProvider;

final class AuditServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Audit';
    }

    public function register(): void
    {
        $this->app->singleton(AuditLogger::class, DatabaseAuditLogger::class);
    }
}
