<?php

namespace App\Modules\Tax\Database\Seeders;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Tax\Infrastructure\Models\CustomsRule;
use App\Modules\Tax\Infrastructure\Models\TaxRule;
use Illuminate\Database\Seeder;

/**
 * Baseline tax configuration. VAT rates are the published standard rates (UAE 5%, KSA 15%).
 * Customs duty (GCC common external tariff baseline 5% CIF) and the de minimis thresholds
 * are PLACEHOLDERS to be confirmed by the customs broker / tax advisor before launch
 * (docs/COUNTRY_CONFIGURATION.md). All values are editable in admin without a deployment.
 */
final class TaxSeeder extends Seeder
{
    public function run(CountryDirectory $countries): void
    {
        $config = [
            'AE' => ['vat' => '5', 'vat_from' => '2018-01-01', 'de_minimis' => '1000.00', 'currency' => 'AED'],
            'SA' => ['vat' => '15', 'vat_from' => '2020-07-01', 'de_minimis' => '1000.00', 'currency' => 'SAR'],
            // Expansion countries (seeded once they are switched on). Kuwait and Qatar have no VAT today.
            'KW' => ['vat' => '0', 'vat_from' => '2026-01-01', 'de_minimis' => '100.000', 'currency' => 'KWD'],
            'QA' => ['vat' => '0', 'vat_from' => '2026-01-01', 'de_minimis' => '1000.00', 'currency' => 'QAR'],
            'BH' => ['vat' => '10', 'vat_from' => '2022-01-01', 'de_minimis' => '100.000', 'currency' => 'BHD'],
            'OM' => ['vat' => '5', 'vat_from' => '2021-04-16', 'de_minimis' => '100.000', 'currency' => 'OMR'],
        ];

        foreach ($config as $code => $c) {
            $country = $countries->find($code);
            if ($country === null) {
                continue;
            }
            TaxRule::query()->firstOrCreate(
                ['country_id' => $country->id, 'tax_type' => 'vat', 'category_id' => null, 'effective_from' => $c['vat_from']],
                ['rate_percent' => $c['vat'], 'is_recoverable' => false, 'applies_to_shipping' => true],
            );
            TaxRule::query()->firstOrCreate(
                ['country_id' => $country->id, 'tax_type' => 'import_vat', 'category_id' => null, 'effective_from' => $c['vat_from']],
                ['rate_percent' => $c['vat'], 'is_recoverable' => true, 'applies_to_shipping' => false],
            );
            CustomsRule::query()->firstOrCreate(
                ['country_id' => $country->id, 'hs_code_prefix' => null, 'effective_from' => '2026-01-01'],
                ['duty_percent' => '5', 'valuation_basis' => 'CIF', 'de_minimis_amount' => $c['de_minimis'], 'de_minimis_currency' => $c['currency']],
            );
        }
    }
}
