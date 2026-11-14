<?php

namespace App\Modules\Suppliers\Application;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Contracts\SellabilityRule;
use App\Modules\Countries\Domain\CountryData;

/** A product is sellable in a country only if at least one variant has an authorized, in-stock source there. */
final class HasAuthorizedSource implements SellabilityRule
{
    public function __construct(private readonly CatalogReader $catalog, private readonly EloquentSourcingDirectory $sourcing) {}

    public function check(string $productId, CountryData $country): ?string
    {
        $variantIds = $this->catalog->variantIdsOf($productId);

        return $this->sourcing->eligible($variantIds, $country->code) === [] ? 'NO_AUTHORIZED_SOURCE' : null;
    }
}
