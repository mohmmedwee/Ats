<?php

namespace App\Modules\Countries\Application\Address;

use App\Modules\Countries\Contracts\AddressRules;
use App\Shared\Exceptions\ApiException;
use Illuminate\Validation\Rule;

/**
 * AE: emirate required, mobile +9715XXXXXXXX, postal code not used.
 * SA: region required, mobile +9665XXXXXXXX, postal code 5 digits, optional National
 *     Address short code (4 letters + 4 digits).
 */
final class StaticAddressRules implements AddressRules
{
    private const REGIONS = [
        'AE' => [
            ['code' => 'AUH', 'en' => 'Abu Dhabi', 'ar' => 'أبوظبي'],
            ['code' => 'DXB', 'en' => 'Dubai', 'ar' => 'دبي'],
            ['code' => 'SHJ', 'en' => 'Sharjah', 'ar' => 'الشارقة'],
            ['code' => 'AJM', 'en' => 'Ajman', 'ar' => 'عجمان'],
            ['code' => 'UAQ', 'en' => 'Umm Al Quwain', 'ar' => 'أم القيوين'],
            ['code' => 'RAK', 'en' => 'Ras Al Khaimah', 'ar' => 'رأس الخيمة'],
            ['code' => 'FUJ', 'en' => 'Fujairah', 'ar' => 'الفجيرة'],
        ],
        'SA' => [
            ['code' => 'RIY', 'en' => 'Riyadh', 'ar' => 'الرياض'],
            ['code' => 'MAK', 'en' => 'Makkah', 'ar' => 'مكة المكرمة'],
            ['code' => 'MED', 'en' => 'Madinah', 'ar' => 'المدينة المنورة'],
            ['code' => 'EAS', 'en' => 'Eastern Province', 'ar' => 'المنطقة الشرقية'],
            ['code' => 'QAS', 'en' => 'Al-Qassim', 'ar' => 'القصيم'],
            ['code' => 'ASR', 'en' => 'Asir', 'ar' => 'عسير'],
            ['code' => 'TAB', 'en' => 'Tabuk', 'ar' => 'تبوك'],
            ['code' => 'HAI', 'en' => 'Hail', 'ar' => 'حائل'],
            ['code' => 'NBR', 'en' => 'Northern Borders', 'ar' => 'الحدود الشمالية'],
            ['code' => 'JAZ', 'en' => 'Jazan', 'ar' => 'جازان'],
            ['code' => 'NAJ', 'en' => 'Najran', 'ar' => 'نجران'],
            ['code' => 'BAH', 'en' => 'Al-Bahah', 'ar' => 'الباحة'],
            ['code' => 'JOF', 'en' => 'Al-Jawf', 'ar' => 'الجوف'],
        ],
    ];

    public function rules(string $countryCode): array
    {
        $code = strtoupper($countryCode);
        $regions = array_column(self::REGIONS[$code] ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422), 'code');

        $common = [
            'full_name' => ['required', 'string', 'max:150'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', Rule::in($regions)],
        ];

        return $common + match ($code) {
            'AE' => [
                'phone' => ['required', 'regex:/^(\+971|00971|0)?5\d{8}$/'],
                'postal_code' => ['nullable', 'string', 'max:10'],
                'national_address_code' => ['prohibited'],
            ],
            'SA' => [
                'phone' => ['required', 'regex:/^(\+966|00966|0)?5\d{8}$/'],
                'postal_code' => ['nullable', 'regex:/^\d{5}$/'],
                'national_address_code' => ['nullable', 'regex:/^[A-Za-z]{4}\d{4}$/'],
            ],
        };
    }

    public function regions(string $countryCode): array
    {
        return self::REGIONS[strtoupper($countryCode)] ?? [];
    }

    public function normalizePhone(string $countryCode, string $phone): string
    {
        $prefix = strtoupper($countryCode) === 'SA' ? '+966' : '+971';
        $digits = preg_replace('/^(\+971|00971|\+966|00966|0)/', '', preg_replace('/\s+/', '', $phone) ?? '') ?? '';

        return $prefix.$digits;
    }
}
