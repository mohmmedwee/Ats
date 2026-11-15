<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogPricing;

/** Default until the Pricing module binds its implementation. */
final class NullCatalogPricing implements CatalogPricing
{
    public function fromPrice(string $productId, string $countryCode): ?array
    {
        return null;
    }

    public function variantPrices(array $variantIds, string $countryCode): array
    {
        return [];
    }

    public function saleTargets(string $countryCode): array
    {
        return ['all' => false, 'product_ids' => [], 'category_ids' => []];
    }
}
