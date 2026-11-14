<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Shipping\Infrastructure\Models\ShippingMethod;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShippingMethodController
{
    public function __construct(private readonly AuditLogger $audit, private readonly CountryDirectory $countries) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(ShippingMethod::query()->orderBy('country_id')->orderBy('sort_order')->get()->map(fn ($m) => $this->present($m))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $country = $this->countries->find($data['country']) ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422);
        $method = ShippingMethod::query()->create(collect($data)->except('country')->all() + ['country_id' => $country->id]);
        $this->audit->record('shipping_method.created', 'shipping_method', $method->id, null, $data);

        return ApiResponse::success($this->present($method), 201);
    }

    public function update(Request $request, ShippingMethod $shippingMethod): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $old = $shippingMethod->only(array_keys($data));
        $shippingMethod->update($data);
        $this->audit->record('shipping_method.updated', 'shipping_method', $shippingMethod->id, $old, $data);

        return ApiResponse::success($this->present($shippingMethod));
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'country' => [$creating ? 'required' : 'prohibited', 'string', 'size:2'],
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
            'name' => [$req, 'array'], 'name.en' => ['required_with:name', 'string', 'max:100'], 'name.ar' => ['required_with:name', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'pricing_model' => [$req, 'in:flat,weight_band'],
            'config' => [$req, 'array'],
            'config.amount' => ['required_if:pricing_model,flat', 'numeric', 'min:0'],
            'config.bands' => ['required_if:pricing_model,weight_band', 'array'],
            'config.bands.*.max_grams' => ['required', 'integer', 'min:1'],
            'config.bands.*.amount' => ['required', 'numeric', 'min:0'],
            'config.above_amount' => ['nullable', 'numeric', 'min:0'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0'],
            'extra_min_days' => ['sometimes', 'integer', 'between:-10,30'],
            'extra_max_days' => ['sometimes', 'integer', 'between:-10,30'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(ShippingMethod $m): array
    {
        $code = null;
        foreach ($this->countries->active() as $c) {
            $code = $c->id === $m->country_id ? $c->code : $code;
        }

        return ['id' => $m->id, 'country' => $code] + $m->only(['code', 'name', 'carrier', 'pricing_model', 'config', 'free_shipping_threshold', 'extra_min_days', 'extra_max_days', 'sort_order', 'is_active']);
    }
}
