<?php

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Application\EloquentOrderBook;
use App\Modules\Orders\Console\ExpireUnpaidOrdersCommand;
use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Orders\Listeners\OrdersOutboxListener;
use App\Shared\Modules\ModuleServiceProvider;
use App\Shared\Outbox\OutboxMessagePublished;
use Illuminate\Support\Facades\Event;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Orders';
    }

    public function register(): void
    {
        $this->app->singleton(OrderBook::class, EloquentOrderBook::class);
        // OrderRefunder (Payments), CancellationPolicy (Procurement) and FulfillmentView
        // (Shipping) are bound by the modules that implement them.
    }

    public function boot(): void
    {
        parent::boot();
        Event::listen(OutboxMessagePublished::class, OrdersOutboxListener::class);
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireUnpaidOrdersCommand::class]);
        }
    }
}
