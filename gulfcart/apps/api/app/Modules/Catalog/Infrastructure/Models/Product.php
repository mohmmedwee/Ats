<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $category_id
 * @property array<string, string|null> $title
 * @property string $slug
 * @property array<string, string|null>|null $description
 * @property string $status
 * @property string|null $brand
 * @property array<string, mixed> $attributes
 * @property int|null $weight_grams
 * @property string|null $hs_code
 * @property string|null $country_of_origin
 * @property Carbon|null $published_at
 */
class Product extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'description' => 'array',
            'attributes' => 'array',
            'weight_grams' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sku');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductCountryAvailability, $this> */
    public function availability(): HasMany
    {
        return $this->hasMany(ProductCountryAvailability::class);
    }
}
