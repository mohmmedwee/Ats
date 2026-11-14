<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $product_id
 * @property string $sku
 * @property array<string, string> $attributes
 * @property int|null $weight_grams
 * @property bool $is_active
 */
class ProductVariant extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'is_active' => 'boolean', 'weight_grams' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
