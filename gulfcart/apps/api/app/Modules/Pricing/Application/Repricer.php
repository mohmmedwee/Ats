<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Events\CatalogListingStale;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Pricing\Contracts\ExchangeRates;
use App\Modules\Pricing\Domain\PricingEngine;
use App\Modules\Pricing\Domain\PricingException;
use App\Modules\Pricing\Domain\PricingInput;
use App\Modules\Pricing\Domain\PricingResult;
use App\Modules\Pricing\Infrastructure\Models\ProductPrice;
use App\Modules\Suppliers\Contracts\SourcingDirectory;
use App\Modules\Suppliers\Domain\SourceData;
use App\Modules\Tax\Contracts\TaxRules;
use App\Shared\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes the stored price of every variant of a product in every active country.
 * For each variant the cheapest eligible source wins (ties: supplier priority).
 * A variant with no eligible source or no valid exchange rate gets no price.
 */
final class Repricer
{
    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly SourcingDirectory $sourcing,
        private readonly CountryDirectory $countries,
        private readonly TaxRules $tax,
        private readonly ExchangeRates $fx,
        private readonly RuleResolver $rules,
        private readonly PricingEngine $engine,
    ) {}

    public function reprice(string $productId, bool $notify = true): void
    {
        $this->rules->flush(); // always price with the current rule set
        $variantIds = $this->catalog->variantIdsOf($productId);
        $variants = $this->catalog->variants($variantIds);

        DB::transaction(function () use ($productId, $variants): void {
            foreach ($this->countries->active() as $country) {
                foreach ($variants as $variant) {
                    $best = $this->bestPrice($variant->id, $productId, $variant->categoryId, $country);
                    if ($best === null) {
                        ProductPrice::query()->where('variant_id', $variant->id)->where('country_id', $country->id)->delete();

                        continue;
                    }
                    [$source, $result, $rule, $fxRow, $vatRate] = $best;
                    ProductPrice::query()->updateOrCreate(
                        ['variant_id' => $variant->id, 'country_id' => $country->id],
                        [
                            'supplier_product_id' => $source->supplierProductId,
                            'gross_price' => $result->grossPrice,
                            'net_price' => $result->netPrice,
                            'vat_amount' => $result->vatAmount,
                            'vat_rate' => $vatRate,
                            'currency' => $country->currency,
                            'variable_cost' => $result->variableCost,
                            'exchange_rate_id' => $fxRow['id'],
                            'calculation' => $result->toArray() + [
                                'supplier_id' => $source->supplierId,
                                'unit_cost' => $source->unitCost,
                                'shipping_cost' => $source->shippingCost,
                                'cost_currency' => $source->costCurrency,
                                'exchange_rate' => $fxRow['rate'],
                                'rule_ids' => $rule,
                            ],
                            'valid_until' => $fxRow['effective_at'] !== null
                                ? now()->parse($fxRow['effective_at'])->addHours((int) config('gulfcart.fx.max_age_hours', 24))
                                : null,
                        ],
                    );
                }
            }
        });

        // Remove prices of variants that are no longer active.
        ProductPrice::query()->whereIn('variant_id', function ($q) use ($productId) {
            $q->select('id')->from('product_variants')->where('product_id', $productId)->where('is_active', false);
        })->delete();

        if ($notify) {
            event(new CatalogListingStale($productId));
        }
    }

    /** @return array{0: SourceData, 1: PricingResult, 2: list<string>, 3: array{rate: string, id: string|null, effective_at: string|null}, 4: string}|null */
    private function bestPrice(string $variantId, string $productId, ?string $categoryId, CountryData $country): ?array
    {
        $lineage = $this->catalog->categoryLineage($categoryId);
        $best = null;

        foreach ($this->sourcing->sourcesFor($variantId, $country->code) as $source) {
            $fxRow = $this->fx->rate($source->costCurrency, $country->currency);
            $rule = $this->rules->resolve($country->id, $source->supplierId, $lineage, $productId);
            if ($fxRow === null || $rule === null) {
                Log::warning('pricing.unpriceable', ['variant_id' => $variantId, 'country' => $country->code, 'fx' => $fxRow !== null, 'rule' => $rule !== null]);

                continue;
            }

            $vatRate = $this->tax->vatRate($country->code, $lineage);
            $config = (array) config('gulfcart.pricing');
            try {
                $result = $this->engine->price(new PricingInput(
                    unitCost: $source->unitCost,
                    shippingCost: $source->shippingCost,
                    costCurrency: $source->costCurrency,
                    saleCurrency: $country->currency,
                    fxRate: $fxRow['rate'],
                    fxBufferPercent: $source->costCurrency === $country->currency ? '0' : (string) ($config['fx_buffer_percent'] ?? '0'),
                    importVatPercent: $this->tax->nonRecoverableImportVatRate($country->code),
                    operationalAllocation: (string) ($config['operational_allocation'][$country->code] ?? '0'),
                    vatPercent: $vatRate,
                    feePercent: (string) ($config['payment_fee_percent'] ?? '0'),
                    feeFixed: (string) ($config['payment_fee_fixed'][$country->code] ?? '0'),
                    rule: $rule,
                    rounding: (string) ($country->settings['rounding'] ?? 'ceil_whole'),
                ));
            } catch (PricingException $e) {
                Log::warning('pricing.rule_rejected', ['variant_id' => $variantId, 'country' => $country->code, 'reason' => $e->reason]);

                continue;
            }

            if ($best === null || Decimal::cmp($result->grossPrice, $best[1]->grossPrice) < 0) {
                $best = [$source, $result, $rule->ruleIds, $fxRow, $vatRate];
            }
        }

        return $best;
    }
}
