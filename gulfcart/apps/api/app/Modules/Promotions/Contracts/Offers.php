<?php

namespace App\Modules\Promotions\Contracts;

/** Automatic quantity offers (buy X get Y). Used by Checkout when building a quote. */
interface Offers
{
    /**
     * @param  list<array{product_id: string, category_ids: list<string>, unit_gross: string, quantity: int}>  $lines
     * @return array{offer_id: string, name: array<string, string|null>, discount: string, line_discounts: array<int, string>}|null best offer, discounts VAT-inclusive per line index
     */
    public function best(array $lines, string $countryCode): ?array;

    /** @return list<array{id: string, name: array<string, string|null>, buy_qty: int, get_qty: int, get_percent: string}> running offers for a product */
    public function forProduct(string $productId, array $categoryIds, string $countryCode): array;
}
