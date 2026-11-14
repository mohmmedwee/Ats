<?php

namespace App\Modules\Pricing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $base_currency
 * @property string $target_currency
 * @property string $rate
 * @property string $source
 * @property Carbon $effective_at
 */
class ExchangeRate extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime'];
    }
}
