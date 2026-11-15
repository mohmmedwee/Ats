<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $slug
 * @property array<string, string|null> $name
 * @property array<string, string|null>|null $description
 * @property string|null $logo_path
 * @property bool $is_authorized
 * @property string|null $authorization_note
 * @property bool $is_featured
 * @property bool $is_active
 * @property int $sort_order
 */
class Brand extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array', 'is_authorized' => 'boolean', 'is_featured' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->url($this->logo_path) : null;
    }
}
