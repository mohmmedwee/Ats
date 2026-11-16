<?php

namespace App\Modules\Promotions\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property array<string, string|null> $name
 * @property int $buy_qty
 * @property int $get_qty
 * @property string $get_percent
 * @property string $scope all|category|product
 * @property string|null $category_id
 * @property string|null $product_id
 * @property list<string> $countries
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 */
class Offer extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'countries' => '[]'];

    protected function casts(): array
    {
        return ['name' => 'array', 'countries' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'buy_qty' => 'integer', 'get_qty' => 'integer'];
    }
}
