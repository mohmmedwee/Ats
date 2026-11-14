<?php

namespace App\Modules\Countries\Database\Seeders;

use App\Modules\Countries\Contracts\CountryDirectory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Launch countries. Tax, customs and shipping rules are seeded in Sprint 2. */
final class CountrySeeder extends Seeder
{
    public function run(CountryDirectory $directory): void
    {
        $countries = [
            [
                'code' => 'AE', 'currency_code' => 'AED', 'default_locale' => 'ar-AE', 'timezone' => 'Asia/Dubai',
                'name' => ['en' => 'United Arab Emirates', 'ar' => 'الإمارات العربية المتحدة'],
                'supported_locales' => '{ar-AE,en-AE}',
                'settings' => ['phone_prefix' => '+971', 'rounding' => 'ceil_whole', 'returns' => ['window_days' => 14]],
            ],
            [
                'code' => 'SA', 'currency_code' => 'SAR', 'default_locale' => 'ar-SA', 'timezone' => 'Asia/Riyadh',
                'name' => ['en' => 'Saudi Arabia', 'ar' => 'المملكة العربية السعودية'],
                'supported_locales' => '{ar-SA,en-SA}',
                'settings' => ['phone_prefix' => '+966', 'rounding' => 'ceil_whole', 'returns' => ['window_days' => 7]],
            ],
        ];

        foreach ($countries as $c) {
            DB::table('countries')->upsert([[
                'id' => (string) Str::uuid7(),
                'code' => $c['code'],
                'name' => json_encode($c['name'], JSON_UNESCAPED_UNICODE),
                'currency_code' => $c['currency_code'],
                'default_locale' => $c['default_locale'],
                'supported_locales' => $c['supported_locales'],
                'timezone' => $c['timezone'],
                'settings' => json_encode($c['settings']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['code'], ['name', 'currency_code', 'default_locale', 'supported_locales', 'timezone', 'settings', 'updated_at']);
        }

        $directory->forget();
    }
}
