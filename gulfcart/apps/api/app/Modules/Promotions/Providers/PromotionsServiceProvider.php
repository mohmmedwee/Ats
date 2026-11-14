<?php

namespace App\Modules\Promotions\Providers;

use App\Modules\Promotions\Application\CouponService;
use App\Modules\Promotions\Contracts\Coupons;
use App\Modules\Promotions\Listeners\PromotionsOutboxListener;
use App\Shared\Modules\ModuleServiceProvider;
use App\Shared\Outbox\OutboxMessagePublished;
use Illuminate\Support\Facades\Event;

final class PromotionsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Promotions';
    }

    public function register(): void
    {
        $this->app->singleton(CouponService::class);
        $this->app->alias(CouponService::class, Coupons::class);
    }

    public function boot(): void
    {
        parent::boot();
        Event::listen(OutboxMessagePublished::class, PromotionsOutboxListener::class);
    }
}
