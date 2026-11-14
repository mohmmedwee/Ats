<?php

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\Domain\VariantPrice;

interface PriceBook
{
    /** Current valid price, or null when the variant cannot be sold in that country right now. */
    public function priceFor(string $variantId, string $countryCode): ?VariantPrice;

    /**
     * @param  list<string>  $variantIds
     * @return array<string, VariantPrice>
     */
    public function pricesFor(array $variantIds, string $countryCode): array;
}
