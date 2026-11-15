<?php

namespace App\Modules\Promotions\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $code
 * @property string|null $user_id personal code (points, referral) usable only by this customer
 * @property string|null $source
 * @property string|null $description
 * @property string $type
 * @property string $value
 * @property string|null $max_discount
 * @property string|null $min_subtotal
 * @property list<string> $countries
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $usage_limit
 * @property int $per_customer_limit
 * @property bool $is_active
 */
class Coupon extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['countries' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
