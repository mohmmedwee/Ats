<?php

namespace App\Modules\Pricing\Providers;

use App\Modules\Catalog\Contracts\CatalogPricing;
use App\Modules\Catalog\Contracts\SellabilityRule;
use App\Modules\Pricing\Application\ConfiguredRateProvider;
use App\Modules\Pricing\Application\EloquentExchangeRates;
use App\Modules\Pricing\Application\EloquentPriceBook;
use App\Modules\Pricing\Application\HasPrice;
use App\Modules\Pricing\Application\RuleResolver;
use App\Modules\Pricing\Console\RefreshExchangeRatesCommand;
use App\Modules\Pricing\Console\RepriceAllCommand;
use App\Modules\Pricing\Contracts\ExchangeRateProvider;
use App\Modules\Pricing\Contracts\ExchangeRates;
use App\Modules\Pricing\Contracts\PriceBook;
use App\Modules\Pricing\Listeners\RepriceProduct;
use App\Modules\Suppliers\Events\SourcingChanged;
use App\Shared\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class PricingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Pricing';
    }

    public function register(): void
    {
        $this->app->singleton(EloquentPriceBook::class);
        $this->app->alias(EloquentPriceBook::class, PriceBook::class);
        $this->app->singleton(CatalogPricing::class, fn ($app) => $app->make(EloquentPriceBook::class));
        $this->app->singleton(EloquentExchangeRates::class);
        $this->app->alias(EloquentExchangeRates::class, ExchangeRates::class);
        $this->app->singletonIf(ExchangeRateProvider::class, ConfiguredRateProvider::class);
        $this->app->scoped(RuleResolver::class);
        $this->app->tag([HasPrice::class], SellabilityRule::TAG);
    }

    public function boot(): void
    {
        parent::boot();
        Event::listen(SourcingChanged::class, RepriceProduct::class);
        if ($this->app->runningInConsole()) {
            $this->commands([RefreshExchangeRatesCommand::class, RepriceAllCommand::class]);
        }
    }
}
