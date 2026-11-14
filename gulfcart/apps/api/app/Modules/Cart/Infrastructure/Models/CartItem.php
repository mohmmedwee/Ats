<?php

namespace App\Modules\Cart\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $cart_id
 * @property string $variant_id
 * @property int $quantity
 * @property string|null $price_at_add
 */
class CartItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
