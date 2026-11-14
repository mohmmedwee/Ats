<?php

namespace App\Modules\Suppliers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $supplier_id
 * @property string $product_id
 * @property string $external_product_id
 * @property string|null $external_sku
 * @property string|null $source_url
 * @property string $cost_price
 * @property string $cost_currency
 * @property string $stock_status
 * @property int $priority
 * @property bool $is_active
 * @property array<string, mixed> $supplier_data
 * @property Carbon|null $last_synced_at
 */
class SupplierProduct extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['supplier_data' => 'array', 'is_active' => 'boolean', 'priority' => 'integer', 'last_synced_at' => 'datetime'];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<SupplierVariantMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(SupplierVariantMapping::class);
    }
}
