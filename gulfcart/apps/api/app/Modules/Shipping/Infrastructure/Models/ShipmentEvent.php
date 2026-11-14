<?php

namespace App\Modules\Shipping\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $shipment_id
 * @property string $event_code
 * @property string|null $description
 * @property string|null $event_location
 * @property Carbon $occurred_at
 */
class ShipmentEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
