<?php

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Gateways\SandboxGateway;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * The payment providers a customer can choose from. PAYMENT_PROVIDER is the default (cards); PAYMENT_METHODS
 * lists every enabled provider in display order, e.g. "tap,paypal". Each payment remembers its provider,
 * so webhooks and refunds always go back to the gateway that took the money.
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private readonly Container $app) {}

    public function default(): PaymentGateway
    {
        return $this->get((string) config('gulfcart.payments.provider', 'sandbox'));
    }

    /** @return list<string> enabled provider names, default first */
    public function enabled(): array
    {
        $default = (string) config('gulfcart.payments.provider', 'sandbox');
        $listed = array_filter(array_map('trim', explode(',', (string) config('gulfcart.payments.methods', ''))));

        $all = array_values(array_unique([$default, ...$listed]));
        if (config('gulfcart.cod.enabled') && ! in_array('cod', $all, true)) {
            $all[] = 'cod';
        }

        return $all;
    }

    public function forCustomer(?string $name): PaymentGateway
    {
        if ($name === null || $name === '') {
            return $this->default();
        }
        if (! in_array($name, $this->enabled(), true)) {
            throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['provider' => ['This payment method is not available.']]]);
        }

        return $this->get($name);
    }

    public function get(string $name): PaymentGateway
    {
        return $this->resolved[$name] ??= $this->make($name);
    }

    private function make(string $name): PaymentGateway
    {
        if ($name === 'sandbox') {
            if ($this->app->environment('production') && ! config('gulfcart.payments.allow_sandbox_in_production')) {
                throw new RuntimeException('The sandbox payment gateway is disabled in production.');
            }

            return new SandboxGateway((string) config('gulfcart.payments.sandbox.secret'), (int) config('gulfcart.payments.webhook_tolerance_seconds', 300));
        }
        $class = config('gulfcart.payments.gateways.'.$name);
        if (! is_string($class) || ! is_a($class, PaymentGateway::class, true)) {
            throw new RuntimeException("Payment provider [{$name}] is not registered.");
        }

        return $this->app->make($class);
    }
}
