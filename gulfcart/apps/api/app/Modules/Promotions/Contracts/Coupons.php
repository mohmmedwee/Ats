<?php

namespace App\Modules\Promotions\Contracts;

use App\Modules\Promotions\Domain\CouponDecision;

interface Coupons
{
    /**
     * Checks a code for a customer and cart, throwing ApiException COUPON_INVALID (with a reason) when it
     * cannot be used. $subtotal is the VAT-inclusive items total in the country currency.
     */
    public function evaluate(string $code, string $userId, string $countryCode, string $subtotal): CouponDecision;

    /** Records use of the code for an order, re-checking limits under a lock. Call inside the order transaction. */
    public function redeem(string $code, string $userId, string $orderId, string $amount, string $currency): void;
}
