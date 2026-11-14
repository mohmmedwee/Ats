<?php

namespace App\Modules\Checkout\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $cart_id
 * @property string $user_id
 * @property string $country_id
 * @property string $address_id
 * @property string $shipping_method_id
 * @property string $currency
 * @property array<string, mixed> $quote
 * @property string $subtotal
 * @property string $shipping_total
 * @property string $tax_total
 * @property string $customs_total
 * @property string $discount_total
 * @property string $grand_total
 * @property string $status
 * @property Carbon $expires_at
 */
class CheckoutSession extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quote' => 'array', 'expires_at' => 'datetime'];
    }

    public function isUsable(): bool
    {
        return $this->status === 'open' && $this->expires_at->isFuture();
    }
}
