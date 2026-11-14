<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Shared\I18n\Translations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
final class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $admin ? $this->name : Translations::pick($this->name),
            'parent_id' => $this->parent_id,
            'sort_order' => $this->sort_order,
            'is_active' => $this->when($admin, $this->is_active),
            'size_guide' => $this->when($admin, $this->size_guide),
            'children' => $this->whenLoaded('children', fn () => CategoryResource::collection($this->children)->resolve($request)),
        ];
    }
}
