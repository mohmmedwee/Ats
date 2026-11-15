<?php

namespace App\Modules\Promotions\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $user_id
 * @property int $balance
 * @property string $referral_code
 * @property string|null $referred_by
 * @property Carbon|null $referral_rewarded_at
 */
class LoyaltyAccount extends Model
{
    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['balance' => 0];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'referral_rewarded_at' => 'datetime'];
    }
}
