<?php

namespace App\Modules\Promotions\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Promotions\Contracts\Offers;
use App\Modules\Promotions\Infrastructure\Models\Offer;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Buy X get Y offers: the badge on a product, and management (pricing.manage). */
final class OfferController
{
    public function __construct(private readonly Offers $offers, private readonly CatalogReader $catalog, private readonly AuditLogger $audit) {}

    public function forProduct(Request $request, string $productId): JsonResponse
    {
        /** @var CountryData $country */
        $country = $request->attributes->get('country');
        $variant = $this->catalog->variant($this->catalog->variantIdsOf($productId)[0] ?? '');
        $lineage = $variant ? $this->catalog->categoryLineage($variant->categoryId) : [];

        return ApiResponse::success(array_map(fn ($o) => $o + ['label' => Translations::pick($o['name'])], $this->offers->forProduct($productId, $lineage, $country->code)));
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(Offer::query()->latest()->get()->map(fn (Offer $o) => $this->present($o))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $offer = Offer::query()->create($data);
        $this->audit->record('offer.created', 'offer', $offer->id, null, $data);

        return ApiResponse::success($this->present($offer->refresh()), 201);
    }

    public function update(Request $request, Offer $offer): JsonResponse
    {
        $data = $this->validated($request, false);
        $old = $offer->only(array_keys($data));
        $offer->update($data);
        $this->audit->record('offer.updated', 'offer', $offer->id, $old, $data);

        return ApiResponse::success($this->present($offer->refresh()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'array'], 'name.en' => ['required_with:name', 'string', 'max:120'], 'name.ar' => ['nullable', 'string', 'max:120'],
            'buy_qty' => [$req, 'integer', 'between:1,20'], 'get_qty' => [$req, 'integer', 'between:1,20'],
            'get_percent' => [$req, 'numeric', 'gt:0', 'max:100'],
            'scope' => [$req, 'in:all,category,product'],
            'category_id' => ['nullable', 'required_if:scope,category', 'uuid', Rule::exists('categories', 'id')],
            'product_id' => ['nullable', 'required_if:scope,product', 'uuid', Rule::exists('products', 'id')],
            'countries' => ['sometimes', 'array'], 'countries.*' => [Rule::in((array) config('gulfcart.countries'))],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['scope'])) {
            $data['category_id'] = $data['scope'] === 'category' ? $data['category_id'] : null;
            $data['product_id'] = $data['scope'] === 'product' ? $data['product_id'] : null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Offer $o): array
    {
        return $o->only(['id', 'name', 'buy_qty', 'get_qty', 'get_percent', 'scope', 'category_id', 'product_id', 'countries', 'is_active'])
            + ['starts_at' => $o->starts_at?->toIso8601String(), 'ends_at' => $o->ends_at?->toIso8601String()];
    }
}
