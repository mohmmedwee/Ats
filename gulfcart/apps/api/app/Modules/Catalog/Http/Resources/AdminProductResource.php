<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Staff view: includes workflow, rights and listing diagnostics. @mixin Product */
final class AdminProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $countries = $request->attributes->get('country_codes_by_id', []);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'category_id' => $this->category_id,
            'brand' => $this->brand,
            'brand_id' => $this->brand_id,
            'specifications' => $this->specifications,
            'warranty' => $this->warranty,
            'attributes' => (object) $this->attributes,
            'weight_grams' => $this->weight_grams,
            'hs_code' => $this->hs_code,
            'country_of_origin' => $this->country_of_origin,
            'published_at' => $this->published_at?->toIso8601String(),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id, 'sku' => $v->sku, 'attributes' => (object) $v->attributes,
                'weight_grams' => $v->weight_grams, 'is_active' => $v->is_active,
            ])->values()),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => [
                'id' => $i->id, 'variant_id' => $i->variant_id, 'url' => $i->publicUrl(), 'source_url' => $i->source_url,
                'rights_status' => $i->rights_status, 'rights_basis' => $i->rights_basis, 'downloaded' => $i->storage_path !== null,
            ])->values()),
            'availability' => $this->whenLoaded('availability', fn () => $this->availability->map(fn ($a) => [
                'country' => $countries[$a->country_id] ?? $a->country_id,
                'is_available' => $a->is_available,
                'min_days' => $a->estimated_delivery_min_days,
                'max_days' => $a->estimated_delivery_max_days,
                'restriction_reason' => $a->restriction_reason,
                'is_sellable' => $a->is_sellable,
                'unsellable_reason' => $a->unsellable_reason,
                'from_price' => $a->from_price !== null ? ['amount' => $a->from_price, 'currency' => $a->from_price_currency] : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
