<?php

namespace App\Modules\Engagement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property string $type back_in_stock|price_drop
 * @property string $country_code
 * @property string|null $price_at_subscribe
 * @property Carbon|null $notified_at
 */
class ProductAlert extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }
}
