<?php

namespace App\Modules\Suppliers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $supplier_id
 * @property string $idempotency_key
 * @property string $external_order_id
 * @property string $status
 * @property list<array<string, mixed>> $items
 * @property string $total_cost
 * @property string $currency
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property list<array<string, mixed>> $events
 */
class MockSupplierOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'events' => 'array'];
    }
}
