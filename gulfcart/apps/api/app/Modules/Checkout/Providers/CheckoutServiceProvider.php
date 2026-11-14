<?php

namespace App\Modules\Checkout\Providers;

use App\Modules\Checkout\Application\CheckoutService;
use App\Modules\Checkout\Contracts\CheckoutSessions;
use App\Shared\Modules\ModuleServiceProvider;

final class CheckoutServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Checkout';
    }

    public function register(): void
    {
        $this->app->singleton(CheckoutService::class);
        $this->app->alias(CheckoutService::class, CheckoutSessions::class);
    }
}
