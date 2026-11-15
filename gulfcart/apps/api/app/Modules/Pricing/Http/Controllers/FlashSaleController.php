<?php

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Pricing\Application\FlashSales;
use App\Modules\Pricing\Infrastructure\Models\FlashSale;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Flash sales: the running sale for the storefront banner, and management (pricing.manage). */
final class FlashSaleController
{
    public function __construct(private readonly AuditLogger $audit, private readonly FlashSales $sales) {}

    /** Sales running now in the request country, biggest first (for the countdown banner). */
    public function current(Request $request): JsonResponse
    {
        /** @var CountryData $country */
        $country = $request->attributes->get('country');

        return ApiResponse::success($this->sales->running($country->code)->map(fn (FlashSale $s) => [
            'id' => $s->id, 'name' => Translations::pick($s->name), 'percent_off' => (string) $s->percent_off,
            'scope' => $s->scope, 'ends_at' => $s->ends_at->toIso8601String(),
        ])->values()->all());
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(FlashSale::query()->orderByDesc('starts_at')->get()->map(fn (FlashSale $s) => $this->present($s))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $sale = FlashSale::query()->create($data);
        $this->audit->record('flash_sale.created', 'flash_sale', $sale->id, null, $data);

        return ApiResponse::success($this->present($sale), 201);
    }

    public function update(Request $request, FlashSale $flashSale): JsonResponse
    {
        $data = $this->validated($request, false);
        $old = $flashSale->only(array_keys($data));
        $flashSale->update($data);
        $this->audit->record('flash_sale.updated', 'flash_sale', $flashSale->id, $old, $data);

        return ApiResponse::success($this->present($flashSale->refresh()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'array'],
            'name.en' => ['required_with:name', 'string', 'max:120'],
            'name.ar' => ['nullable', 'string', 'max:120'],
            'percent_off' => [$req, 'numeric', 'gt:0', 'max:90'],
            'scope' => [$req, 'in:all,category,product'],
            'category_id' => ['nullable', 'required_if:scope,category', 'uuid', Rule::exists('categories', 'id')],
            'product_id' => ['nullable', 'required_if:scope,product', 'uuid', Rule::exists('products', 'id')],
            'countries' => ['sometimes', 'array'],
            'countries.*' => [Rule::in((array) config('gulfcart.countries'))],
            'starts_at' => [$req, 'date'],
            'ends_at' => [$req, 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['scope'])) {
            $data['category_id'] = $data['scope'] === 'category' ? $data['category_id'] : null;
            $data['product_id'] = $data['scope'] === 'product' ? $data['product_id'] : null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(FlashSale $s): array
    {
        return $s->only(['id', 'name', 'percent_off', 'scope', 'category_id', 'product_id', 'countries', 'is_active']) + [
            'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
            'status' => ! $s->is_active ? 'paused' : ($s->ends_at->isPast() ? 'ended' : ($s->starts_at->isFuture() ? 'scheduled' : 'running')),
        ];
    }
}
