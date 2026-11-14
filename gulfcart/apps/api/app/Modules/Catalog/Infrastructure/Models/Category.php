<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string|null $parent_id
 * @property array<string, string|null> $name
 * @property string $slug
 * @property int $sort_order
 * @property bool $is_active
 */
class Category extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
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
}
