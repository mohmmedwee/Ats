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
        'KW' => [
            ['code' => 'CAP', 'en' => 'Al Asimah (Capital)', 'ar' => 'العاصمة'], ['code' => 'HAW', 'en' => 'Hawalli', 'ar' => 'حولي'],
            ['code' => 'FAR', 'en' => 'Al Farwaniyah', 'ar' => 'الفروانية'], ['code' => 'AHM', 'en' => 'Al Ahmadi', 'ar' => 'الأحمدي'],
            ['code' => 'JAH', 'en' => 'Al Jahra', 'ar' => 'الجهراء'], ['code' => 'MUB', 'en' => 'Mubarak Al-Kabeer', 'ar' => 'مبارك الكبير'],
        ],
        'QA' => [
            ['code' => 'DOH', 'en' => 'Doha', 'ar' => 'الدوحة'], ['code' => 'RAY', 'en' => 'Al Rayyan', 'ar' => 'الريان'],
            ['code' => 'WAK', 'en' => 'Al Wakrah', 'ar' => 'الوكرة'], ['code' => 'KHO', 'en' => 'Al Khor', 'ar' => 'الخور'],
            ['code' => 'SHA', 'en' => 'Al Shamal', 'ar' => 'الشمال'], ['code' => 'DAA', 'en' => 'Al Daayen', 'ar' => 'الضعاين'],
            ['code' => 'UMS', 'en' => 'Umm Salal', 'ar' => 'أم صلال'], ['code' => 'SHH', 'en' => 'Al Shahaniya', 'ar' => 'الشحانية'],
        ],
        'BH' => [
            ['code' => 'CAP', 'en' => 'Capital', 'ar' => 'العاصمة'], ['code' => 'MUH', 'en' => 'Muharraq', 'ar' => 'المحرق'],
            ['code' => 'NOR', 'en' => 'Northern', 'ar' => 'الشمالية'], ['code' => 'SOU', 'en' => 'Southern', 'ar' => 'الجنوبية'],
        ],
        'OM' => [
            ['code' => 'MUS', 'en' => 'Muscat', 'ar' => 'مسقط'], ['code' => 'DHO', 'en' => 'Dhofar', 'ar' => 'ظفار'],
            ['code' => 'BAN', 'en' => 'North Al Batinah', 'ar' => 'شمال الباطنة'], ['code' => 'BAS', 'en' => 'South Al Batinah', 'ar' => 'جنوب الباطنة'],
            ['code' => 'DAK', 'en' => 'Ad Dakhiliyah', 'ar' => 'الداخلية'], ['code' => 'SHN', 'en' => 'North Ash Sharqiyah', 'ar' => 'شمال الشرقية'],
            ['code' => 'SHS', 'en' => 'South Ash Sharqiyah', 'ar' => 'جنوب الشرقية'], ['code' => 'DHA', 'en' => 'Ad Dhahirah', 'ar' => 'الظاهرة'],
            ['code' => 'BUR', 'en' => 'Al Buraimi', 'ar' => 'البريمي'], ['code' => 'WUS', 'en' => 'Al Wusta', 'ar' => 'الوسطى'],
            ['code' => 'MSN', 'en' => 'Musandam', 'ar' => 'مسندم'],
        ],
    ];

    /** Mobile number patterns and dialling prefixes. */
    private const PHONES = [
        'AE' => ['+971', '5\\d{8}'], 'SA' => ['+966', '5\\d{8}'], 'KW' => ['+965', '[569]\\d{7}'],
        'QA' => ['+974', '[3567]\\d{7}'], 'BH' => ['+973', '3\\d{7}'], 'OM' => ['+968', '[79]\\d{7}'],
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
            default => [
                'phone' => ['required', 'regex:/^(\\'.self::PHONES[$code][0].'|00'.ltrim(self::PHONES[$code][0], '+').')?'.self::PHONES[$code][1].'$/'],
                'postal_code' => ['nullable', 'string', 'max:10'],
                'national_address_code' => ['prohibited'],
            ],
        };
    }

    public function regions(string $countryCode): array
    {
        return self::REGIONS[strtoupper($countryCode)] ?? [];
    }

    public function normalizePhone(string $countryCode, string $phone): string
    {
        $prefix = self::PHONES[strtoupper($countryCode)][0] ?? '+971';
        $code = ltrim($prefix, '+');
        $digits = preg_replace('/^(\+'.$code.'|00'.$code.'|0)/', '', preg_replace('/\s+/', '', $phone) ?? '') ?? '';

        return $prefix.$digits;
    }
}
