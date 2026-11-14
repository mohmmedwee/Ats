<?php

namespace App\Modules\Engagement\Providers;

use App\Shared\Modules\ModuleServiceProvider;

final class EngagementServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Engagement';
    }
}
