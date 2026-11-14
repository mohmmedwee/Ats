<?php

namespace App\Modules\Shipping\Database\Seeders;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Shipping\Infrastructure\Models\ShippingMethod;
use Illuminate\Database\Seeder;

/** Launch defaults; adjust in admin once courier agreements (decision D3) are signed. */
final class ShippingMethodSeeder extends Seeder
{
    public function run(CountryDirectory $countries): void
    {
        $methods = [
            'AE' => [
                ['code' => 'standard', 'name' => ['en' => 'Standard delivery', 'ar' => 'توصيل عادي'], 'pricing_model' => 'weight_band',
                    'config' => ['bands' => [['max_grams' => 2000, 'amount' => '15.00'], ['max_grams' => 5000, 'amount' => '25.00']], 'above_amount' => '40.00'],
                    'free_shipping_threshold' => '200.00', 'extra_min_days' => 0, 'extra_max_days' => 0, 'sort_order' => 1],
                ['code' => 'express', 'name' => ['en' => 'Express delivery', 'ar' => 'توصيل سريع'], 'pricing_model' => 'flat',
                    'config' => ['amount' => '35.00'], 'free_shipping_threshold' => null, 'extra_min_days' => -2, 'extra_max_days' => -3, 'sort_order' => 2],
            ],
            'SA' => [
                ['code' => 'standard', 'name' => ['en' => 'Standard delivery', 'ar' => 'توصيل عادي'], 'pricing_model' => 'weight_band',
                    'config' => ['bands' => [['max_grams' => 2000, 'amount' => '15.00'], ['max_grams' => 5000, 'amount' => '25.00']], 'above_amount' => '40.00'],
                    'free_shipping_threshold' => '200.00', 'extra_min_days' => 0, 'extra_max_days' => 0, 'sort_order' => 1],
                ['code' => 'express', 'name' => ['en' => 'Express delivery', 'ar' => 'توصيل سريع'], 'pricing_model' => 'flat',
                    'config' => ['amount' => '39.00'], 'free_shipping_threshold' => null, 'extra_min_days' => -2, 'extra_max_days' => -3, 'sort_order' => 2],
            ],
        ];

        foreach ($methods as $code => $rows) {
            $country = $countries->find($code);
            foreach ($country ? $rows : [] as $row) {
                ShippingMethod::query()->firstOrCreate(['country_id' => $country->id, 'code' => $row['code']], $row);
            }
        }
    }
}
