<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Pricing\Domain\VariantPrice;
use App\Modules\Pricing\Infrastructure\Models\FlashSale;
use App\Shared\Money\Decimal;
use Illuminate\Support\Collection;

/**
 * Applies running flash sales to prices. The discounted price is what the cart, checkout and
 * order use, so the customer always pays what was shown. A sale never takes a price below cost:
 * the net price stays at or above the variable cost (supplier price, FX, shipping, fees).
 */
final class FlashSales
{
    /** @var array<string, list<string>> */
    private array $lineage = [];

    public function __construct(private readonly CatalogReader $catalog) {}

    /** @return Collection<int, FlashSale> sales running now in that country */
    public function running(string $countryCode): Collection
    {
        // Memoised on the request itself, so it never outlives the request that loaded it.
        $request = app()->bound('request') ? app('request') : null;
        $key = 'flash_sales.'.$countryCode;
        if ($request !== null && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }
        $sales = FlashSale::query()->where('is_active', true)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->get()
            ->filter(fn (FlashSale $s) => $s->countries === [] || in_array($countryCode, $s->countries, true))
            ->sortByDesc(fn (FlashSale $s) => (float) $s->percent_off)->values();
        $request?->attributes->set($key, $sales);

        return $sales;
    }

    /** Biggest running sale covering the product (directly, its category or a parent category). */
    public function saleFor(string $productId, ?string $categoryId, string $countryCode): ?FlashSale
    {
        $sales = $this->running($countryCode);
        if ($sales->isEmpty()) {
            return null;
        }
        $lineage = $categoryId === null ? [] : ($this->lineage[$categoryId] ??= $this->catalog->categoryLineage($categoryId));

        return $sales->first(fn (FlashSale $s) => $s->scope === 'all'
            || ($s->scope === 'product' && $s->product_id === $productId)
            || ($s->scope === 'category' && in_array($s->category_id, $lineage, true)));
    }

    /**
     * @param  array<string, VariantPrice>  $prices
     * @return array<string, VariantPrice>
     */
    public function applyAll(array $prices, string $countryCode): array
    {
        if ($prices === [] || $this->running($countryCode)->isEmpty()) {
            return $prices;
        }
        $variants = $this->catalog->variants(array_keys($prices));
        foreach ($prices as $id => $price) {
            $variant = $variants[$id] ?? null;
            $sale = $variant ? $this->saleFor($variant->productId, $variant->categoryId, $countryCode) : null;
            if ($sale !== null) {
                $prices[$id] = $this->apply($price, $sale);
            }
        }

        return $prices;
    }

    public function apply(VariantPrice $p, FlashSale $sale): VariantPrice
    {
        $factor = Decimal::sub('1', Decimal::div((string) $sale->percent_off, '100', 6), 6);
        $gross = Decimal::round(Decimal::mul($p->gross, $factor, 6), 2);
        $onePlusVat = Decimal::add('1', Decimal::div($p->vatRate, '100', 6), 6);
        // Floor: never below cost.
        $floor = Decimal::div(Decimal::ceil(Decimal::mul(Decimal::mul($p->variableCost, $onePlusVat, 6), '100', 6)), '100', 2);
        if (Decimal::cmp($gross, $floor) < 0) {
            $gross = $floor;
        }
        if (Decimal::cmp($gross, $p->gross) >= 0) {
            return $p;
        }
        $net = Decimal::round(Decimal::div($gross, $onePlusVat, 6), 2);

        return new VariantPrice(
            $p->variantId, $p->countryCode, $p->currency, $gross, $net, Decimal::sub($gross, $net, 2), $p->vatRate,
            $p->supplierProductId, $p->variableCost, $p->exchangeRateId, $p->exchangeRate,
            $p->calculation + ['flash_sale' => ['id' => $sale->id, 'percent_off' => (string) $sale->percent_off, 'regular_gross' => $p->gross]],
            compareAt: $p->gross, saleEndsAt: $sale->ends_at->toIso8601String(),
        );
    }

    /** @return array{all: bool, product_ids: list<string>, category_ids: list<string>} what the running sales cover */
    public function targets(string $countryCode): array
    {
        $sales = $this->running($countryCode);

        return [
            'all' => $sales->contains('scope', 'all'),
            'product_ids' => $sales->where('scope', 'product')->pluck('product_id')->values()->all(),
            'category_ids' => $sales->where('scope', 'category')->pluck('category_id')->values()->all(),
        ];
    }
}
