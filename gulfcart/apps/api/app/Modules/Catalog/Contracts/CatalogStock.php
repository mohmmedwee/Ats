<?php

namespace App\Modules\Catalog\Contracts;

/** Customer-facing stock state. Implemented by the Suppliers module. */
interface CatalogStock
{
    /**
     * @param  list<string>  $variantIds
     * @return array<string, 'in_stock'|'low_stock'|'out_of_stock'> missing = out_of_stock
     */
    public function variantAvailability(array $variantIds, string $countryCode): array;
}
