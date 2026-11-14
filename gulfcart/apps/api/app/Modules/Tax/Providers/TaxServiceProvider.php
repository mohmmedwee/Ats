<?php

namespace App\Modules\Tax\Providers;

use App\Modules\Tax\Application\EloquentTaxRules;
use App\Modules\Tax\Contracts\TaxRules;
use App\Shared\Modules\ModuleServiceProvider;

final class TaxServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Tax';
    }

    public function register(): void
    {
        $this->app->singleton(TaxRules::class, EloquentTaxRules::class);
    }
}
