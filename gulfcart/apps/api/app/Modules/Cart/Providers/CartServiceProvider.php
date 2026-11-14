<?php

namespace App\Modules\Cart\Providers;

use App\Modules\Cart\Application\CartService;
use App\Modules\Cart\Application\EloquentAbandonedCarts;
use App\Modules\Cart\Contracts\AbandonedCarts;
use App\Modules\Cart\Contracts\CartReader;
use App\Shared\Modules\ModuleServiceProvider;

final class CartServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Cart';
    }

    public function register(): void
    {
        $this->app->singleton(CartService::class);
        $this->app->alias(CartService::class, CartReader::class);
        $this->app->singleton(AbandonedCarts::class, EloquentAbandonedCarts::class);
    }
}
