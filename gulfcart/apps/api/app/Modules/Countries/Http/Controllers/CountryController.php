<?php

namespace App\Modules\Countries\Http\Controllers;

use App\Modules\Countries\Contracts\AddressRules;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Countries\Domain\CountryData;
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

    public function current(Request $request): JsonResponse
    {
        /** @var CountryData $country */
        $country = $request->attributes->get('country');

        return ApiResponse::success($country->toPublicArray(app()->getLocale()));
    }
}
