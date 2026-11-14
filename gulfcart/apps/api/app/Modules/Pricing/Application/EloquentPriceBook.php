<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Catalog\Contracts\CatalogPricing;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Pricing\Contracts\PriceBook;
use App\Modules\Pricing\Domain\VariantPrice;
use App\Modules\Pricing\Infrastructure\Models\ProductPrice;

final class EloquentPriceBook implements CatalogPricing, PriceBook
{
    public function __construct(private readonly CountryDirectory $countries, private readonly CatalogReader $catalog) {}

    public function priceFor(string $variantId, string $countryCode): ?VariantPrice
    {
        return $this->pricesFor([$variantId], $countryCode)[$variantId] ?? null;
    }

    public function pricesFor(array $variantIds, string $countryCode): array
    {
        $country = $this->countries->find($countryCode);
        if ($country === null || $variantIds === []) {
            return [];
        }

        $rows = ProductPrice::query()->where('country_id', $country->id)->whereIn('variant_id', $variantIds)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->variant_id] = new VariantPrice(
                $r->variant_id, $country->code, $r->currency, (string) $r->gross_price, (string) $r->net_price,
                (string) $r->vat_amount, (string) $r->vat_rate, $r->supplier_product_id, (string) $r->variable_cost,
                $r->exchange_rate_id, (string) ($r->calculation['exchange_rate'] ?? '1'), $r->calculation,
            );
        }

        return $out;
    }

    public function fromPrice(string $productId, string $countryCode): ?array
    {
        $prices = $this->pricesFor($this->catalog->variantIdsOf($productId), $countryCode);
        if ($prices === []) {
            return null;
        }
        $min = collect($prices)->sortBy(fn (VariantPrice $p) => (float) $p->gross)->first();

        return ['amount' => $min->gross, 'currency' => $min->currency];
    }

    public function variantPrices(array $variantIds, string $countryCode): array
    {
        return array_map(fn (VariantPrice $p) => ['amount' => $p->gross, 'currency' => $p->currency], $this->pricesFor($variantIds, $countryCode));
    }
}
