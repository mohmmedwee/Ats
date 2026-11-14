<?php

namespace App\Modules\Payments\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string $payment_id
 * @property string|null $return_id
 * @property string $amount
 * @property string $currency
 * @property string $reason_code
 * @property string|null $reason
 * @property array<string, int> $item_quantities
 * @property string $status
 * @property string $idempotency_key
 * @property string|null $provider_reference
 * @property string|null $requested_by
 * @property string|null $approved_by
 * @property string|null $failure_reason
 */
class Refund extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['item_quantities' => 'array'];
    }
}
