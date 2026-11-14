<?php

namespace App\Modules\Cart\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string|null $guest_token
 * @property string $country_id
 * @property string $currency
 * @property string $status
 */
class Cart extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('created_at');
    }
}
