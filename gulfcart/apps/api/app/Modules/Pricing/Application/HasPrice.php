<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Contracts\SellabilityRule;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Pricing\Contracts\PriceBook;

final class HasPrice implements SellabilityRule
{
    public function __construct(private readonly PriceBook $prices, private readonly CatalogReader $catalog) {}

    public function check(string $productId, CountryData $country): ?string
    {
        return $this->prices->pricesFor($this->catalog->variantIdsOf($productId), $country->code) === [] ? 'NO_PRICE' : null;
    }
}
