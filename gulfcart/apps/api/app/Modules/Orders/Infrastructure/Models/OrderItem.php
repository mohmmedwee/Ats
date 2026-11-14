<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string|null $product_id
 * @property string|null $variant_id
 * @property string $supplier_product_id
 * @property string $supplier_id
 * @property array<string, mixed> $product_snapshot
 * @property int $quantity
 * @property string $unit_gross_price
 * @property string $unit_net_price
 * @property string $unit_tax
 * @property string $vat_rate
 * @property string $line_total
 * @property string $unit_supplier_cost
 * @property string $unit_supplier_shipping
 * @property string $supplier_currency
 * @property string|null $exchange_rate_id
 * @property string $exchange_rate
 * @property array<string, mixed> $pricing_snapshot
 * @property int $shipment_group
 * @property string $fulfillment_status
 * @property int $refunded_quantity
 */
class OrderItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['product_snapshot' => 'array', 'pricing_snapshot' => 'array', 'quantity' => 'integer', 'shipment_group' => 'integer', 'refunded_quantity' => 'integer'];
    }
}
