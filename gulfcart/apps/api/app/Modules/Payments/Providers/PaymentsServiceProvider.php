<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Orders\Contracts\OrderRefunder;
use App\Modules\Payments\Application\Gateways\SandboxGateway;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Application\RefundService;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Shared\Modules\ModuleServiceProvider;
use RuntimeException;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Payments';
    }

    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function ($app) {
            $provider = (string) config('gulfcart.payments.provider', 'sandbox');
            if ($provider === 'sandbox') {
                if ($app->environment('production') && ! config('gulfcart.payments.allow_sandbox_in_production')) {
                    throw new RuntimeException('The sandbox payment gateway is disabled in production.');
                }

                return new SandboxGateway((string) config('gulfcart.payments.sandbox.secret'), (int) config('gulfcart.payments.webhook_tolerance_seconds', 300));
            }
            $class = config('gulfcart.payments.gateways.'.$provider);
            if (! is_string($class) || ! is_a($class, PaymentGateway::class, true)) {
                throw new RuntimeException("Payment provider [{$provider}] is not registered.");
            }

            return $app->make($class);
        });
        $this->app->singleton(RefundService::class);
        $this->app->alias(RefundService::class, OrderRefunder::class);
        $this->app->singleton(PaymentService::class);
    }
}
