<?php

namespace App\Modules\Pricing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Time-boxed percentage discount on everything, a category tree or one product.
 *
 * @property string $id
 * @property array<string, string|null> $name
 * @property string $percent_off
 * @property string $scope all|category|product
 * @property string|null $category_id
 * @property string|null $product_id
 * @property list<string> $countries empty = every country
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $is_active
 */
class FlashSale extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'countries' => '[]'];

    protected function casts(): array
    {
        return ['name' => 'array', 'countries' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function isRunning(): bool
    {
        return $this->is_active && $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
