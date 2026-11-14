<?php

namespace App\Modules\Content\Providers;

use App\Shared\Modules\ModuleServiceProvider;

final class ContentServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Content';
    }
}
