<?php

namespace App\Modules\Shipping\Application;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\Domain\ShippingOption;
use App\Modules\Shipping\Infrastructure\Models\ShippingMethod;
use App\Shared\Money\Decimal;

final class EloquentShippingQuoter implements ShippingQuoter
{
    public function __construct(private readonly CountryDirectory $countries) {}

    public function options(string $countryCode, string $subtotalGross, int $totalWeightGrams): array
    {
        $country = $this->countries->find($countryCode);
        if ($country === null) {
            return [];
        }

        return ShippingMethod::query()->where('country_id', $country->id)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')->get()
            ->map(fn (ShippingMethod $m) => $this->quote($m, $country->currency, $subtotalGross, $totalWeightGrams))
            ->filter()->values()->all();
    }

    public function freeShippingThreshold(string $countryCode): ?string
    {
        $country = $this->countries->find($countryCode);
        $min = $country === null ? null : ShippingMethod::query()->where('country_id', $country->id)->where('is_active', true)
            ->whereNotNull('free_shipping_threshold')->min('free_shipping_threshold');

        return $min === null ? null : Decimal::round((string) $min, 2);
    }

    public function option(string $countryCode, string $code, string $subtotalGross, int $totalWeightGrams): ?ShippingOption
    {
        foreach ($this->options($countryCode, $subtotalGross, $totalWeightGrams) as $option) {
            if ($option->code === $code) {
                return $option;
            }
        }

        return null;
    }

    private function quote(ShippingMethod $m, string $currency, string $subtotal, int $grams): ?ShippingOption
    {
        $amount = match ($m->pricing_model) {
            'flat' => (string) ($m->config['amount'] ?? '0'),
            'weight_band' => $this->band($m->config, $grams),
            default => null,
        };
        if ($amount === null) {
            return null;
        }

        $free = $m->free_shipping_threshold !== null && Decimal::cmp($subtotal, (string) $m->free_shipping_threshold) >= 0;

        return new ShippingOption($m->id, $m->code, $m->name, $free ? '0.00' : Decimal::round($amount, 2), $currency, $m->extra_min_days, $m->extra_max_days, $free);
    }

    /** @param array<string, mixed> $config */
    private function band(array $config, int $grams): ?string
    {
        $bands = $config['bands'] ?? [];
        usort($bands, fn ($a, $b) => $a['max_grams'] <=> $b['max_grams']);
        foreach ($bands as $band) {
            if ($grams <= (int) $band['max_grams']) {
                return (string) $band['amount'];
            }
        }

        return isset($config['above_amount']) ? (string) $config['above_amount'] : null;
    }
}
