<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string|null $parent_id
 * @property array<string, string|null> $name
 * @property string $slug
 * @property int $sort_order
 * @property bool $is_active
 * @property string|null $image_path
 * @property bool $requires_authorized_brand
 */
class Category extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'size_guide' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer', 'requires_authorized_brand' => 'boolean'];
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->url($this->image_path) : null;
    }

    /** True when this category or any parent only sells authorized brands. */
    public function requiresAuthorizedBrand(): bool
    {
        for ($c = $this, $guard = 0; $c !== null && $guard < 10; $guard++) {
            if ($c->requires_authorized_brand) {
                return true;
            }
            $c = $c->parent_id ? self::query()->find($c->parent_id) : null;
        }

        return false;
    }
}
