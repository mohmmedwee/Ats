<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Orders\Contracts\OrderRefunder;
use App\Modules\Payments\Application\GatewayRegistry;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Application\RefundService;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Shared\Modules\ModuleServiceProvider;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Payments';
    }

    public function register(): void
    {
        $this->app->singleton(GatewayRegistry::class);
        // The default gateway (cards); payments chosen with another provider resolve through the registry.
        $this->app->singleton(PaymentGateway::class, fn ($app) => $app->make(GatewayRegistry::class)->default());
        $this->app->singleton(RefundService::class);
        $this->app->alias(RefundService::class, OrderRefunder::class);
        $this->app->singleton(PaymentService::class);
    }
}
