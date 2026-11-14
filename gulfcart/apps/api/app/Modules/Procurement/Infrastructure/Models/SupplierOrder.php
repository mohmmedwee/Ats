<?php

namespace App\Modules\Procurement\Infrastructure\Models;

use App\Modules\Procurement\Domain\SupplierOrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $reference
 * @property string $order_id
 * @property string $supplier_id
 * @property int $shipment_group
 * @property string|null $external_order_id
 * @property string $status
 * @property string $idempotency_key
 * @property int $key_version
 * @property string $procurement_mode
 * @property string $expected_cost
 * @property string|null $actual_cost
 * @property string $cost_currency
 * @property string|null $exchange_rate
 * @property array<string, string|null> $shipping_address
 * @property array<string, mixed> $supplier_response
 * @property string|null $exception_reason
 * @property array<string, mixed>|null $exception_details
 * @property Carbon|null $purchased_at
 * @property Carbon|null $last_tracked_at
 */
class SupplierOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array', 'supplier_response' => 'array', 'exception_details' => 'array',
            'purchased_at' => 'datetime', 'last_tracked_at' => 'datetime', 'shipment_group' => 'integer', 'key_version' => 'integer',
        ];
    }

    /** @return HasMany<SupplierOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    /** @return HasOne<ProcurementTask, $this> */
    public function task(): HasOne
    {
        return $this->hasOne(ProcurementTask::class);
    }

    /** Validated status change. */
    public function moveTo(string $status, array $attributes = []): void
    {
        SupplierOrderStatus::assertTransition($this->status, $status);
        $this->update(['status' => $status] + $attributes);
    }
}
