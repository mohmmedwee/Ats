<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_number
 * @property string $user_id
 * @property string $checkout_session_id
 * @property string $country_id
 * @property string|null $address_id
 * @property array<string, string|null> $shipping_address_snapshot
 * @property array<string, mixed> $shipping_method_snapshot
 * @property string $currency
 * @property string $locale
 * @property string $status
 * @property string $refund_state
 * @property string $subtotal
 * @property string $shipping_total
 * @property string $tax_total
 * @property string $customs_total
 * @property string $discount_total
 * @property string $grand_total
 * @property string $paid_total
 * @property string $refunded_total
 * @property Carbon $placed_at
 * @property Carbon|null $paid_at
 * @property Carbon $payment_due_at
 */
class Order extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'shipping_address_snapshot' => 'array',
            'shipping_method_snapshot' => 'array',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_due_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('shipment_group')->orderBy('created_at');
    }
}
