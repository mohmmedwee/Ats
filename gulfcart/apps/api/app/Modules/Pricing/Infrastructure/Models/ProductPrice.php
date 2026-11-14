<?php

namespace App\Modules\Pricing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $variant_id
 * @property string $country_id
 * @property string $supplier_product_id
 * @property string $gross_price
 * @property string $net_price
 * @property string $vat_amount
 * @property string $vat_rate
 * @property string $currency
 * @property string $variable_cost
 * @property string|null $exchange_rate_id
 * @property array<string, mixed> $calculation
 * @property Carbon|null $valid_until
 */
class ProductPrice extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['calculation' => 'array', 'valid_until' => 'datetime'];
    }
}
