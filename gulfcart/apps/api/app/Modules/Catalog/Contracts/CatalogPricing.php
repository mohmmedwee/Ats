<?php

namespace App\Modules\Catalog\Contracts;

/** Customer-facing prices for catalog display. Implemented by the Pricing module. */
interface CatalogPricing
{
    /**
     * Lowest variant price. compare_at / sale_ends_at are set while a flash sale lowers it.
     *
     * @return array{amount: string, currency: string, compare_at?: string, sale_ends_at?: string|null}|null
     */
    public function fromPrice(string $productId, string $countryCode): ?array;

    /**
     * @param  list<string>  $variantIds
     * @return array<string, array{amount: string, currency: string, compare_at?: string, sale_ends_at?: string|null}>
     */
    public function variantPrices(array $variantIds, string $countryCode): array;

    /** @return array{all: bool, product_ids: list<string>, category_ids: list<string>} what running flash sales cover */
    public function saleTargets(string $countryCode): array;
}
