<?php

namespace App\Modules\Promotions\Application;

use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Modules\Promotions\Infrastructure\Models\LoyaltyAccount;
use App\Modules\Promotions\Infrastructure\Models\LoyaltyTransaction;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Points and referrals.
 *  - Paid orders earn points on the items total (not shipping or duties).
 *  - Refunds take back points in proportion; the balance never goes below zero.
 *  - Points turn into a personal one-time discount code, so the discount, VAT and refunds
 *    follow the same path as any coupon.
 *  - A new customer can enter a friend's code before their first paid order: they get a
 *    welcome code, and the friend gets bonus points once that first order is paid.
 */
final class LoyaltyService
{
    public function __construct(private readonly OrderBook $orders) {}

    /** @return array<string, mixed> */
    public static function settings(): array
    {
        return [
            'points_per_unit' => (int) config('gulfcart.loyalty.points_per_unit', 1),
            'point_value' => (string) config('gulfcart.loyalty.point_value', '0.05'),
            'min_redeem' => (int) config('gulfcart.loyalty.min_redeem', 100),
            'referrer_points' => (int) config('gulfcart.loyalty.referrer_points', 500),
            'friend_percent' => (int) config('gulfcart.loyalty.friend_percent', 10),
            'friend_max' => (string) config('gulfcart.loyalty.friend_max', '50'),
        ];
    }

    public function account(string $userId): LoyaltyAccount
    {
        $existing = LoyaltyAccount::query()->find($userId);
        if ($existing !== null) {
            return $existing;
        }
        for ($i = 0; $i < 5; $i++) {
            try {
                return LoyaltyAccount::query()->create(['user_id' => $userId, 'referral_code' => self::code(6)]);
            } catch (QueryException) {
                // Code collision or a concurrent create: re-read and retry.
                if (($again = LoyaltyAccount::query()->find($userId)) !== null) {
                    return $again;
                }
            }
        }
        throw new \RuntimeException('Could not allocate a referral code.');
    }

    public function earnFor(string $orderId): void
    {
        $order = $this->orders->get($orderId);
        if ($order === null || ! self::enabled()) {
            return;
        }
        $base = Decimal::sub(Decimal::sub($order->grandTotal, $order->shippingTotal, 2), $order->customsTotal, 2);
        $points = (int) floor((float) $base) * self::settings()['points_per_unit'];

        DB::transaction(function () use ($order, $points) {
            $account = $this->lock($order->userId);
            if ($points > 0 && ! $this->hasEntry($order->id, 'earn')) {
                $this->post($account, 'earn', $points, $order->id, $order->orderNumber);
            }

            // First paid order of a referred customer: reward the friend who invited them.
            if ($account->referred_by !== null && $account->referral_rewarded_at === null) {
                $account->forceFill(['referral_rewarded_at' => now()])->save();
                $friend = $this->lock($account->referred_by);
                $this->post($friend, 'referral_bonus', self::settings()['referrer_points'], $order->id, 'referral');
            }
        });
    }

    /** Takes back points in proportion to what has been refunded so far. */
    public function reverseFor(string $orderId): void
    {
        $order = $this->orders->get($orderId);
        if ($order === null || Decimal::isZero($order->grandTotal)) {
            return;
        }
        DB::transaction(function () use ($order) {
            $account = $this->lock($order->userId);
            $earned = (int) LoyaltyTransaction::query()->where('order_id', $order->id)->where('type', 'earn')->sum('points');
            $reversed = -(int) LoyaltyTransaction::query()->where('order_id', $order->id)->where('type', 'reversal')->sum('points');
            $share = min(1.0, (float) $order->refundedTotal / (float) $order->grandTotal);
            $due = (int) floor($earned * $share) - $reversed;
            $take = min($due, $account->balance);
            if ($take > 0) {
                $this->post($account, 'reversal', -$take, $order->id, $order->orderNumber);
            }
        });
    }

    /** Converts points into a one-time code worth points × point value in the customer's currency. */
    public function redeem(string $userId, int $points, string $countryCode, string $currency): Coupon
    {
        $s = self::settings();
        if ($points < $s['min_redeem'] || $points % $s['min_redeem'] !== 0) {
            throw new ApiException('LOYALTY_INVALID_POINTS', 422, ['min' => $s['min_redeem']], (string) __('errors.loyalty_points', ['min' => $s['min_redeem']]));
        }

        return DB::transaction(function () use ($userId, $points, $countryCode, $currency, $s) {
            $account = $this->lock($userId);
            if ($account->balance < $points) {
                throw new ApiException('LOYALTY_INSUFFICIENT', 422, ['balance' => $account->balance], (string) __('errors.loyalty_insufficient'));
            }
            $amount = Decimal::round(Decimal::mul((string) $points, $s['point_value'], 4), 2);
            $coupon = $this->personalCoupon($userId, 'PTS', 'loyalty', ['type' => 'fixed', 'value' => $amount, 'countries' => [$countryCode], 'ends_at' => now()->addDays(90),
                'description' => "{$points} points → {$amount} {$currency}"]);
            $this->post($account, 'redeem', -$points, null, $coupon->code);

            return $coupon;
        });
    }

    /** A new customer enters a friend's code: they get a welcome code; the friend is rewarded later. */
    public function applyReferral(string $userId, string $code): Coupon
    {
        $code = mb_strtoupper(trim($code));
        $friend = LoyaltyAccount::query()->where('referral_code', $code)->first();
        $fail = fn (string $reason) => new ApiException('REFERRAL_INVALID', 422, ['reason' => $reason], (string) __("errors.referral_{$reason}"));
        if ($friend === null) {
            throw $fail('not_found');
        }
        if ($friend->user_id === $userId) {
            throw $fail('own_code');
        }

        return DB::transaction(function () use ($userId, $friend, $fail) {
            $account = $this->lock($userId);
            if ($account->referred_by !== null) {
                throw $fail('already_used');
            }
            if (LoyaltyTransaction::query()->where('user_id', $userId)->where('type', 'earn')->exists()) {
                throw $fail('not_new');
            }
            $account->forceFill(['referred_by' => $friend->user_id])->save();
            $s = self::settings();

            return $this->personalCoupon($userId, 'HI', 'referral', ['type' => 'percent', 'value' => $s['friend_percent'], 'max_discount' => $s['friend_max'], 'ends_at' => now()->addDays(30),
                'description' => "Welcome {$s['friend_percent']}%"]);
        });
    }

    /** Manual correction by staff (signed). */
    public function adjust(string $userId, int $points, string $note): LoyaltyAccount
    {
        return DB::transaction(function () use ($userId, $points, $note) {
            $account = $this->lock($userId);
            $points = max($points, -$account->balance);
            if ($points !== 0) {
                $this->post($account, 'adjustment', $points, null, $note);
            }

            return $account;
        });
    }

    public static function enabled(): bool
    {
        return (bool) config('gulfcart.loyalty.enabled', true);
    }

    private function lock(string $userId): LoyaltyAccount
    {
        $this->account($userId);

        return LoyaltyAccount::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    private function hasEntry(string $orderId, string $type): bool
    {
        return LoyaltyTransaction::query()->where('order_id', $orderId)->where('type', $type)->exists();
    }

    private function post(LoyaltyAccount $account, string $type, int $points, ?string $orderId, ?string $note): void
    {
        LoyaltyTransaction::query()->create(['user_id' => $account->user_id, 'type' => $type, 'points' => $points, 'order_id' => $orderId, 'note' => $note]);
        $account->forceFill(['balance' => $account->balance + $points])->save();
    }

    /** @param array<string, mixed> $attrs */
    private function personalCoupon(string $userId, string $prefix, string $source, array $attrs): Coupon
    {
        return Coupon::query()->create($attrs + [
            'code' => $prefix.'-'.self::code(8), 'user_id' => $userId, 'source' => $source,
            'countries' => [], 'usage_limit' => 1, 'per_customer_limit' => 1, 'is_active' => true,
        ]);
    }

    private static function code(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
