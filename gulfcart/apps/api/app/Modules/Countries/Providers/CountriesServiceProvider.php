<?php

namespace App\Modules\Countries\Providers;

use App\Modules\Countries\Application\EloquentCountryDirectory;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Shared\Modules\ModuleServiceProvider;

final class CountriesServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Countries';
    }

    public function register(): void
    {
        $this->app->singleton(CountryDirectory::class, EloquentCountryDirectory::class);
    }
}
