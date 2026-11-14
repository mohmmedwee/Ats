<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogStock;

/** Default until the Suppliers module binds its implementation. */
final class NullCatalogStock implements CatalogStock
{
    public function variantAvailability(array $variantIds, string $countryCode): array
    {
        return [];
    }
}
