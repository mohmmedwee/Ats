<?php

namespace App\Modules\Shipping\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $country_id
 * @property string $code
 * @property array<string, string|null> $name
 * @property string|null $carrier
 * @property string $pricing_model
 * @property array<string, mixed> $config flat: {amount}; weight_band: {bands: [{max_grams, amount}], above_amount}
 * @property string|null $free_shipping_threshold
 * @property int $extra_min_days
 * @property int $extra_max_days
 * @property bool $is_active
 */
class ShippingMethod extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'config' => 'array', 'is_active' => 'boolean', 'extra_min_days' => 'integer', 'extra_max_days' => 'integer'];
    }
}
