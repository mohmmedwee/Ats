<?php

namespace App\Modules\Analytics\Providers;

use App\Shared\Modules\ModuleServiceProvider;

final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Analytics';
    }
}
