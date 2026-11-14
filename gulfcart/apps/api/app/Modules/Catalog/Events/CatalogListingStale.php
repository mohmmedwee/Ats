<?php

namespace App\Modules\Catalog\Events;

/**
 * Raised by any module whose data affects whether/how a product is listed
 * (sourcing, stock, prices). Catalog refreshes the listing read model.
 */
final class CatalogListingStale
{
    public function __construct(public readonly string $productId) {}
}
