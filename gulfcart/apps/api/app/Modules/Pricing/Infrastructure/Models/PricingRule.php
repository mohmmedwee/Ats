<?php

namespace App\Modules\Pricing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $scope
 * @property string|null $country_id
 * @property string|null $supplier_id
 * @property string|null $category_id
 * @property string|null $product_id
 * @property string $rule_type
 * @property string $value
 * @property string|null $min_margin_percent
 * @property string|null $max_selling_price
 * @property int $priority
 * @property bool $is_active
 */
class PricingRule extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'priority' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
