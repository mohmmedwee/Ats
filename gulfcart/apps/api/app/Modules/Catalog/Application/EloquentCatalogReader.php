<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Domain\VariantData;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;

final class EloquentCatalogReader implements CatalogReader
{
    public function variant(string $variantId): ?VariantData
    {
        return $this->variants([$variantId])[$variantId] ?? null;
    }

    public function variants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = ProductVariant::query()
            ->with(['product' => fn ($q) => $q->withTrashed()->with('images')])
            ->whereIn('id', array_values(array_unique($variantIds)))
            ->get();

        $out = [];
        foreach ($rows as $v) {
            $p = $v->product;
            $image = $p->images->first(fn ($i) => $i->isDisplayable() && ($i->variant_id === null || $i->variant_id === $v->id));
            $out[$v->id] = new VariantData(
                $v->id, $p->id, $v->sku, $v->attributes, $v->is_active,
                $p->title, $p->slug, $p->trashed() ? 'archived' : $p->status, $p->category_id, $p->brand,
                $v->weight_grams ?? $p->weight_grams, $p->hs_code, $image?->publicUrl(),
            );
        }

        return $out;
    }

    public function variantIdsOf(string $productId): array
    {
        return ProductVariant::query()->where('product_id', $productId)->where('is_active', true)
            ->orderBy('sku')->pluck('id')->all();
    }

    public function categoryLineage(?string $categoryId): array
    {
        $lineage = [];
        $guard = 0;
        while ($categoryId !== null && $guard++ < 20) {
            $lineage[] = $categoryId;
            $categoryId = Category::query()->withTrashed()->whereKey($categoryId)->value('parent_id');
        }

        return $lineage;
    }

    public function productIdOfVariant(string $variantId): ?string
    {
        $id = ProductVariant::query()->whereKey($variantId)->value('product_id');

        return is_string($id) ? $id : null;
    }
}
