<?php

namespace App\Modules\Suppliers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $supplier_product_id
 * @property string $variant_id
 * @property string $external_variant_id
 * @property string|null $cost_price
 * @property string|null $stock_status
 */
class SupplierVariantMapping extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @return BelongsTo<SupplierProduct, $this> */
    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function effectiveCost(): string
    {
        return $this->cost_price ?? $this->supplierProduct->cost_price;
    }

    public function effectiveStock(): string
    {
        return $this->stock_status ?? $this->supplierProduct->stock_status;
    }
}
