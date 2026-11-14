<?php

namespace App\Modules\Returns\Providers;

use App\Shared\Modules\ModuleServiceProvider;

final class ReturnsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Returns';
    }
}
