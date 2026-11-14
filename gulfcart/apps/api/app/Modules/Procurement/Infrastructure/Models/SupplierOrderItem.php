<?php

namespace App\Modules\Procurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $supplier_order_id
 * @property string $order_item_id
 * @property string $supplier_product_id
 * @property string $external_product_id
 * @property string $external_variant_id
 * @property int $purchase_quantity
 * @property string $purchase_cost
 * @property string $shipping_cost
 * @property string $currency
 */
class SupplierOrderItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['purchase_quantity' => 'integer'];
    }
}
