<?php

namespace App\Modules\Catalog\Contracts;

/** Customer-facing prices for catalog display. Implemented by the Pricing module. */
interface CatalogPricing
{
    /** @return array{amount: string, currency: string}|null lowest variant price */
    public function fromPrice(string $productId, string $countryCode): ?array;

    /**
     * @param  list<string>  $variantIds
     * @return array<string, array{amount: string, currency: string}>
     */
    public function variantPrices(array $variantIds, string $countryCode): array;
}
