<?php

namespace App\Modules\Tax\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $country_id
 * @property string $tax_type
 * @property string|null $category_id
 * @property string $rate_percent
 * @property bool $is_recoverable
 * @property bool $applies_to_shipping
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class TaxRule extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_recoverable' => 'boolean', 'applies_to_shipping' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    }
}
