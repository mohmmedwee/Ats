<?php

namespace App\Modules\Procurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $supplier_order_id
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property array<string, mixed>|null $last_error
 * @property Carbon|null $locked_at
 * @property string|null $locked_by
 * @property string|null $assigned_to
 */
class ProcurementTask extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_error' => 'array', 'next_attempt_at' => 'datetime', 'locked_at' => 'datetime', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<SupplierOrder, $this> */
    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    /** @return HasMany<ProcurementAttempt, $this> */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(ProcurementAttempt::class)->orderBy('attempt_number')->orderBy('created_at');
    }
}
