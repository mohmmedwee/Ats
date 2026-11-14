<?php

namespace App\Modules\Tax\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $country_id
 * @property string|null $hs_code_prefix
 * @property string $duty_percent
 * @property string $valuation_basis
 * @property string|null $de_minimis_amount
 * @property string|null $de_minimis_currency
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class CustomsRule extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }
}
