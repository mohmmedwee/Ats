<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $product_id
 * @property string $country_id
 * @property bool $is_available
 * @property int|null $estimated_delivery_min_days
 * @property int|null $estimated_delivery_max_days
 * @property string|null $restriction_reason
 * @property bool $is_sellable
 * @property string|null $unsellable_reason
 * @property string|null $from_price
 * @property string|null $from_price_currency
 */
class ProductCountryAvailability extends Model
{
    public const CREATED_AT = null;

    protected $table = 'product_country_availability';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'is_sellable' => 'boolean',
            'estimated_delivery_min_days' => 'integer',
            'estimated_delivery_max_days' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
