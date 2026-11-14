<?php

namespace App\Modules\Shipping\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property string $supplier_order_id
 * @property int $shipment_group
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property string $status
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 */
class Shipment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'shipment_group' => 'integer'];
    }

    /** @return HasMany<ShipmentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderByDesc('occurred_at');
    }
}
