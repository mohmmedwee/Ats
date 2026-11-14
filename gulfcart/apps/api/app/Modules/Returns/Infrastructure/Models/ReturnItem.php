<?php

namespace App\Modules\Returns\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $return_id
 * @property string $order_item_id
 * @property int $quantity
 * @property string $reason_code
 * @property string|null $condition
 * @property string $unit_refund
 */
class ReturnItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
