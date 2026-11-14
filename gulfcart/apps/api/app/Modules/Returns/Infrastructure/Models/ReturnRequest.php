<?php

namespace App\Modules\Returns\Infrastructure\Models;

use App\Modules\Returns\Domain\ReturnStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $order_id
 * @property string $user_id
 * @property string $rma_number
 * @property string $status
 * @property string $reason_code
 * @property string|null $customer_note
 * @property string|null $staff_note
 * @property string|null $refund_amount
 * @property string $currency
 * @property string|null $refund_id
 * @property string|null $handled_by
 */
class ReturnRequest extends Model
{
    use HasUuids;

    protected $table = 'returns';

    protected $guarded = [];

    /** @return HasMany<ReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    public function moveTo(string $status, array $attributes = []): void
    {
        ReturnStatus::assertTransition($this->status, $status);
        $this->update(['status' => $status] + $attributes);
    }
}
