<?php

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Domain\VariantData;

interface CatalogReader
{
    public function variant(string $variantId): ?VariantData;

    /**
     * @param  list<string>  $variantIds
     * @return array<string, VariantData> keyed by variant ID
     */
    public function variants(array $variantIds): array;

    /** @return list<string> active variant IDs of a product */
    public function variantIdsOf(string $productId): array;

    /** @return list<string> category ID followed by its ancestors, nearest first */
    public function categoryLineage(?string $categoryId): array;

    public function productIdOfVariant(string $variantId): ?string;
}
