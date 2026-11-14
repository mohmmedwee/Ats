<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\ExchangeRateProvider;

/**
 * Rates from config('gulfcart.fx.rates'). AED and SAR are pegged to USD; other pairs in the
 * config are placeholders. Bind a live provider (ExchangeRateProvider) before launch.
 */
final class ConfiguredRateProvider implements ExchangeRateProvider
{
    public function name(): string
    {
        return 'config';
    }

    public function fetch(): array
    {
        $out = [];
        foreach ((array) config('gulfcart.fx.rates', []) as $base => $targets) {
            foreach ((array) $targets as $target => $rate) {
                $out[] = ['base' => (string) $base, 'target' => (string) $target, 'rate' => (string) $rate];
            }
        }

        return $out;
    }
}
