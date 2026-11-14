<?php

namespace App\Modules\Catalog\Contracts;

use App\Modules\Countries\Domain\CountryData;

/**
 * A condition a published product must meet to be sold in a country. Other modules
 * register implementations (tag: catalog.sellability_rules), e.g. Suppliers
 * (authorized source) and Pricing (valid price).
 */
interface SellabilityRule
{
    public const TAG = 'catalog.sellability_rules';

    /** @return string|null null when satisfied, otherwise a reason code such as NO_AUTHORIZED_SOURCE */
    public function check(string $productId, CountryData $country): ?string;
}
