<?php

namespace App\Modules\Countries\Http\Controllers;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Countries\Domain\CountryData;
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

    public function current(Request $request): JsonResponse
    {
        /** @var CountryData $country */
        $country = $request->attributes->get('country');

        return ApiResponse::success($country->toPublicArray(app()->getLocale()));
    }
}
