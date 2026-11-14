<?php

namespace App\Modules\Pricing\Database\Seeders;

use App\Modules\Pricing\Application\EloquentExchangeRates;
use App\Modules\Pricing\Contracts\ExchangeRateProvider;
use App\Modules\Pricing\Infrastructure\Models\PricingRule;
use Illuminate\Database\Seeder;

/** Global default: 20% target contribution margin, never below 10%. Plus an initial FX snapshot. */
final class PricingSeeder extends Seeder
{
    public function run(EloquentExchangeRates $rates, ExchangeRateProvider $provider): void
    {
        PricingRule::query()->firstOrCreate(
            ['scope' => 'global', 'country_id' => null],
            ['rule_type' => 'margin_percent', 'value' => '20', 'min_margin_percent' => '10', 'priority' => 100, 'is_active' => true],
        );
        $rates->refresh($provider);
    }
}
