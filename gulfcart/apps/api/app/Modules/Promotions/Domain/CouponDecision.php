<?php

namespace App\Modules\Promotions\Domain;

/** What a coupon gives on a given cart: an amount off the items (VAT inclusive) and/or free shipping. */
final class CouponDecision
{
    public function __construct(
        public readonly string $code,
        public readonly string $type,
        public readonly string $discount,
        public readonly bool $freeShipping,
        public readonly ?string $description = null,
    ) {}
}
