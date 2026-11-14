<?php

namespace App\Modules\Payments\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property string $idempotency_key
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property string $refunded_amount
 * @property string|null $method
 * @property string|null $failure_code
 * @property string|null $redirect_url
 * @property Carbon|null $paid_at
 */
class Payment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }
}
