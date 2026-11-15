<?php

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Contracts\Coupons;
use App\Modules\Promotions\Domain\CouponDecision;
use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Modules\Promotions\Infrastructure\Models\CouponRedemption;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;

/**
 * Coupon rules. Amounts of fixed coupons and thresholds are in the currency of the country the coupon is
 * limited to; a fixed or minimum-spend coupon without a single country applies the number as-is to each
 * market's currency. Unpaid orders that expire or are cancelled release their redemption.
 */
final class CouponService implements Coupons
{
    public function evaluate(string $code, string $userId, string $countryCode, string $subtotal): CouponDecision
    {
        $coupon = Coupon::query()->where('code', self::normalize($code))->first();

        return $this->decide($coupon, $userId, $countryCode, $subtotal);
    }

    public function redeem(string $code, string $userId, string $orderId, string $amount, string $currency): void
    {
        $coupon = Coupon::query()->where('code', self::normalize($code))->lockForUpdate()->first();
        $this->checkUsage($coupon, $userId);
        CouponRedemption::query()->create([
            'coupon_id' => $coupon->id, 'user_id' => $userId, 'order_id' => $orderId, 'amount' => $amount, 'currency' => $currency, 'status' => 'active',
        ]);
    }

    public function release(string $orderId): void
    {
        CouponRedemption::query()->where('order_id', $orderId)->where('status', 'active')->update(['status' => 'released', 'updated_at' => now()]);
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    private function decide(?Coupon $coupon, string $userId, string $countryCode, string $subtotal): CouponDecision
    {
        if ($coupon === null || ! $coupon->is_active || ($coupon->user_id !== null && $coupon->user_id !== $userId)) {
            throw self::invalid('not_found');
        }
        if (($coupon->starts_at && $coupon->starts_at->isFuture()) || ($coupon->ends_at && $coupon->ends_at->isPast())) {
            throw self::invalid('expired');
        }
        if ($coupon->countries !== [] && ! in_array($countryCode, $coupon->countries, true)) {
            throw self::invalid('country');
        }
        if ($coupon->min_subtotal !== null && Decimal::cmp($subtotal, (string) $coupon->min_subtotal) < 0) {
            throw self::invalid('min_subtotal', ['min_subtotal' => (string) $coupon->min_subtotal]);
        }
        $this->checkUsage($coupon, $userId);

        $discount = match ($coupon->type) {
            'percent' => Decimal::round(Decimal::div(Decimal::mul($subtotal, (string) $coupon->value, 8), '100', 8), 2),
            'fixed' => Decimal::round((string) $coupon->value, 2),
            default => '0.00',
        };
        if ($coupon->max_discount !== null && Decimal::cmp($discount, (string) $coupon->max_discount) > 0) {
            $discount = Decimal::round((string) $coupon->max_discount, 2);
        }
        if (Decimal::cmp($discount, $subtotal) > 0) {
            $discount = Decimal::round($subtotal, 2);
        }

        return new CouponDecision($coupon->code, $coupon->type, $discount, $coupon->type === 'free_shipping', $coupon->description);
    }

    private function checkUsage(?Coupon $coupon, string $userId): void
    {
        if ($coupon === null || ! $coupon->is_active) {
            throw self::invalid('not_found');
        }
        $active = CouponRedemption::query()->where('coupon_id', $coupon->id)->where('status', 'active');
        if ($coupon->usage_limit !== null && (clone $active)->count() >= $coupon->usage_limit) {
            throw self::invalid('used_up');
        }
        if ($coupon->per_customer_limit > 0 && (clone $active)->where('user_id', $userId)->count() >= $coupon->per_customer_limit) {
            throw self::invalid('already_used');
        }
    }

    /** @param array<string, string> $extra */
    private static function invalid(string $reason, array $extra = []): ApiException
    {
        return new ApiException('COUPON_INVALID', 422, ['reason' => $reason] + $extra, (string) __("errors.coupon_{$reason}", $extra));
    }
}
