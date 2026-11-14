<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Infrastructure\Models\CustomerAddress;
use App\Modules\Countries\Contracts\AddressRules;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class AddressController
{
    public function __construct(private readonly AddressRules $rules, private readonly CountryDirectory $countries) {}

    public function index(Request $request): JsonResponse
    {
        $rows = CustomerAddress::query()->where('user_id', $request->user()->getAuthIdentifier())->orderByDesc('is_default')->latest()->get();

        return ApiResponse::success($rows->map(fn ($a) => $this->present($a))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $code = strtoupper((string) $request->validate(['country' => ['required', 'string', 'size:2']])['country']);
        $country = $this->countries->find($code) ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422, ['country' => $code]);
        $this->cleanPhone($request);
        $data = $request->validate($this->rules->rules($code) + ['is_default' => ['sometimes', 'boolean']]);
        $data['phone'] = $this->rules->normalizePhone($code, $data['phone']);
        $userId = (string) $request->user()->getAuthIdentifier();

        $address = DB::transaction(function () use ($data, $userId, $country) {
            $first = ! CustomerAddress::query()->where('user_id', $userId)->where('country_id', $country->id)->exists();
            $default = ($data['is_default'] ?? false) || $first;
            if ($default) {
                CustomerAddress::query()->where('user_id', $userId)->where('country_id', $country->id)->update(['is_default' => false]);
            }

            return CustomerAddress::query()->create($data + ['user_id' => $userId, 'country_id' => $country->id, 'is_default' => $default]);
        });

        return ApiResponse::success($this->present($address), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $address = $this->owned($request, $id);
        $code = $this->code($address->country_id);
        $this->cleanPhone($request);
        $rules = array_map(fn ($r) => array_merge(['sometimes'], array_values(array_filter((array) $r, fn ($x) => $x !== 'required'))), $this->rules->rules($code));
        $data = $request->validate($rules + ['is_default' => ['sometimes', 'boolean']]);
        if (isset($data['phone'])) {
            $data['phone'] = $this->rules->normalizePhone($code, $data['phone']);
        }

        DB::transaction(function () use ($data, $address) {
            if (($data['is_default'] ?? false) === true) {
                CustomerAddress::query()->where('user_id', $address->user_id)->where('country_id', $address->country_id)->update(['is_default' => false]);
            }
            $address->update($data);
        });

        return ApiResponse::success($this->present($address->refresh()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->owned($request, $id)->delete();

        return response()->noContent();
    }

    /** Customers type numbers with spaces or dashes ("050 123 4567"). */
    private function cleanPhone(Request $request): void
    {
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => preg_replace('/[\s\-().]/', '', $request->input('phone'))]);
        }
    }

    private function owned(Request $request, string $id): CustomerAddress
    {
        return CustomerAddress::query()->where('user_id', $request->user()->getAuthIdentifier())->whereKey($id)->first()
            ?? throw new ApiException('NOT_FOUND', 404);
    }

    private function code(string $countryId): string
    {
        foreach ($this->countries->active() as $c) {
            if ($c->id === $countryId) {
                return $c->code;
            }
        }
        throw new ApiException('COUNTRY_NOT_SUPPORTED', 422);
    }

    /** @return array<string, mixed> */
    private function present(CustomerAddress $a): array
    {
        return ['id' => $a->id, 'country' => $this->code($a->country_id)]
            + $a->only(['full_name', 'phone', 'address_line_1', 'address_line_2', 'district', 'city', 'state', 'postal_code', 'national_address_code', 'is_default']);
    }
}
