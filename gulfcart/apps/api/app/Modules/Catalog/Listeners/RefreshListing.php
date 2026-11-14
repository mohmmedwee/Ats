<?php

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Events\CatalogListingStale;

final class RefreshListing
{
    public function __construct(private readonly ListingRefresher $refresher) {}

    public function handle(CatalogListingStale $event): void
    {
        $this->refresher->refresh($event->productId);
    }
}
