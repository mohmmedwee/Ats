<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogPricing;
use App\Modules\Catalog\Contracts\SellabilityRule;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductCountryAvailability;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Countries\Domain\CountryData;

/**
 * Recomputes the storefront listing state (is_sellable, from_price) of a product
 * for every country row. A product is sellable in a country when it is published,
 * available there, has a displayable image, an active variant, and every
 * registered SellabilityRule passes.
 */
final class ListingRefresher
{
    /** @param iterable<SellabilityRule> $rules */
    public function __construct(
        private readonly iterable $rules,
        private readonly CountryDirectory $countries,
        private readonly CatalogPricing $pricing,
    ) {}

    public function refresh(string $productId): void
    {
        $product = Product::query()->with(['images', 'variants', 'availability'])->find($productId);
        if ($product === null) {
            return;
        }

        $byId = [];
        foreach ($this->countries->active() as $c) {
            $byId[$c->id] = $c;
        }

        foreach ($product->availability as $row) {
            $country = $byId[$row->country_id] ?? null;
            $reason = $country === null ? 'COUNTRY_INACTIVE' : $this->reason($product, $row, $country);
            $price = $reason === null && $country !== null ? $this->pricing->fromPrice($product->id, $country->code) : null;

            ProductCountryAvailability::query()
                ->where('product_id', $row->product_id)
                ->where('country_id', $row->country_id)
                ->update([
                    'is_sellable' => $reason === null,
                    'unsellable_reason' => $reason,
                    'from_price' => $price['amount'] ?? null,
                    'from_price_currency' => $price['currency'] ?? null,
                    'refreshed_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private function reason(Product $product, ProductCountryAvailability $row, CountryData $country): ?string
    {
        if ($product->status !== ProductStatus::PUBLISHED) {
            return 'NOT_PUBLISHED';
        }
        if (! $row->is_available) {
            return 'NOT_AVAILABLE_IN_COUNTRY';
        }
        if (! $product->images->contains(fn ($i) => $i->isDisplayable())) {
            return 'NO_APPROVED_IMAGE';
        }
        if (! $product->variants->contains(fn ($v) => $v->is_active)) {
            return 'NO_ACTIVE_VARIANT';
        }
        foreach ($this->rules as $rule) {
            $reason = $rule->check($product->id, $country);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
