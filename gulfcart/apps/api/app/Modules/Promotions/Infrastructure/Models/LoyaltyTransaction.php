<?php

namespace App\Modules\Promotions\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $type earn|reversal|redeem|referral_bonus|adjustment
 * @property int $points signed
 * @property string|null $order_id
 * @property string|null $note
 * @property Carbon $created_at
 */
class LoyaltyTransaction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['points' => 'integer', 'created_at' => 'datetime'];
    }
}
