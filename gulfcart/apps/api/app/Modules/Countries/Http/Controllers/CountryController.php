<?php

namespace App\Modules\Countries\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Countries\Contracts\AddressRules;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Countries\Infrastructure\Models\Country;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CountryController
{
    public function index(CountryDirectory $countries): JsonResponse
    {
        $locale = app()->getLocale();

        return ApiResponse::success(array_map(static fn (CountryData $c) => $c->toPublicArray($locale), $countries->active()));
    }

    public function addressFormat(string $code, CountryDirectory $countries, AddressRules $rules): JsonResponse
    {
        $country = $countries->find($code) ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422, ['country' => strtoupper($code)]);
        $locale = app()->getLocale();

        return ApiResponse::success([
            'country' => $country->code,
            'regions' => array_map(fn ($r) => ['code' => $r['code'], 'name' => $r[$locale] ?? $r['en']], $rules->regions($country->code)),
            'postal_code' => $country->code === 'SA' ? 'optional_5_digits' : 'not_used',
            'national_address_code' => $country->code === 'SA' ? 'optional' : 'not_used',
            'phone_prefix' => $country->settings['phone_prefix'] ?? null,
        ]);
    }

    /** Admin: all countries with settings (countries.manage). */
    public function adminIndex(): JsonResponse
    {
        return ApiResponse::success(Country::query()->orderBy('code')->get()
            ->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'currency' => $c->currency_code, 'is_active' => $c->is_active, 'settings' => (object) $c->settings])->all());
    }

    /** Admin: update operational settings; tax, customs and shipping have their own endpoints. */
    public function adminUpdate(Request $request, string $code, CountryDirectory $countries, AuditLogger $audit): JsonResponse
    {
        $country = Country::query()->where('code', strtoupper($code))->first()
            ?? throw new ApiException('NOT_FOUND', 404);
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'settings.rounding' => ['sometimes', 'in:ceil_whole,ceil_x9,ceil_0_05,ceil_cent'],
            'settings.returns.window_days' => ['sometimes', 'integer', 'between:0,90'],
            'settings.payment_methods' => ['sometimes', 'array'],
            'settings.payment_methods.*' => ['string', 'in:card,apple_pay,mada,google_pay,tabby,tamara'],
            'settings.seller' => ['sometimes', 'array'],
            'settings.seller.legal_name' => ['sometimes', 'string', 'max:255'],
            'settings.seller.vat_number' => ['sometimes', 'string', 'max:50'],
            'settings.importer_of_record' => ['sometimes', 'string', 'max:255'],
        ]);
        $old = ['is_active' => $country->is_active, 'settings' => $country->settings];
        $country->update([
            'is_active' => $data['is_active'] ?? $country->is_active,
            'settings' => array_replace_recursive($country->settings, $data['settings'] ?? []),
        ]);
        $countries->forget();
        $audit->record('country.updated', 'country', $country->id, $old, $data);

        return ApiResponse::success(['code' => $country->code, 'is_active' => $country->is_active, 'settings' => (object) $country->settings]);
    }

    public function current(Request $request): JsonResponse
    {
        /** @var CountryData $country */
        $country = $request->attributes->get('country');

        return ApiResponse::success($country->toPublicArray(app()->getLocale()));
    }
}
