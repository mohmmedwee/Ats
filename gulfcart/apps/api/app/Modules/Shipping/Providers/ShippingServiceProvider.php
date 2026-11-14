<?php

namespace App\Modules\Shipping\Providers;

use App\Modules\Shipping\Application\EloquentShippingQuoter;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Shared\Modules\ModuleServiceProvider;

final class ShippingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Shipping';
    }

    public function register(): void
    {
        $this->app->singleton(ShippingQuoter::class, EloquentShippingQuoter::class);
    }
}
