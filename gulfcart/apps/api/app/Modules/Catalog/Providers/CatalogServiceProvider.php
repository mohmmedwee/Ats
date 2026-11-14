<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Application\EloquentCatalogReader;
use App\Modules\Catalog\Application\EloquentCatalogWriter;
use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Application\NullCatalogPricing;
use App\Modules\Catalog\Application\NullCatalogStock;
use App\Modules\Catalog\Contracts\CatalogPricing;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Contracts\CatalogStock;
use App\Modules\Catalog\Contracts\CatalogWriter;
use App\Modules\Catalog\Contracts\SellabilityRule;
use App\Modules\Catalog\Events\CatalogListingStale;
use App\Modules\Catalog\Listeners\RefreshListing;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Shared\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Catalog';
    }

    public function register(): void
    {
        $this->app->singleton(CatalogReader::class, EloquentCatalogReader::class);
        $this->app->singleton(CatalogWriter::class, EloquentCatalogWriter::class);
        // Overridden by the Pricing and Suppliers modules.
        $this->app->singletonIf(CatalogPricing::class, NullCatalogPricing::class);
        $this->app->singletonIf(CatalogStock::class, NullCatalogStock::class);

        $this->app->bind(ListingRefresher::class, fn ($app) => new ListingRefresher(
            $app->tagged(SellabilityRule::TAG),
            $app->make(CountryDirectory::class),
            $app->make(CatalogPricing::class),
        ));
    }

    public function boot(): void
    {
        parent::boot();
        Event::listen(CatalogListingStale::class, RefreshListing::class);
    }
}
