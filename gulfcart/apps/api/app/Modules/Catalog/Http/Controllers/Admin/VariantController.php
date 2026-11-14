<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Http\Requests\VariantRequest;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class VariantController
{
    public function __construct(private readonly AuditLogger $audit, private readonly ListingRefresher $listing) {}

    public function store(VariantRequest $request, Product $product): JsonResponse
    {
        $variant = $product->variants()->create($request->validated() + ['is_active' => true]);
        $this->audit->record('variant.created', 'product_variant', $variant->id, null, $request->validated());
        $this->listing->refresh($product->id);

        return ApiResponse::success($this->present($variant), 201);
    }

    public function update(VariantRequest $request, ProductVariant $variant): JsonResponse
    {
        $data = $request->validated();
        $old = $variant->only(array_keys($data));
        $variant->update($data);
        $this->audit->record('variant.updated', 'product_variant', $variant->id, $old, $data);
        $this->listing->refresh($variant->product_id);

        return ApiResponse::success($this->present($variant));
    }

    /** @return array<string, mixed> */
    private function present(ProductVariant $v): array
    {
        return ['id' => $v->id, 'product_id' => $v->product_id, 'sku' => $v->sku, 'attributes' => (object) $v->attributes, 'weight_grams' => $v->weight_grams, 'is_active' => $v->is_active];
    }
}
