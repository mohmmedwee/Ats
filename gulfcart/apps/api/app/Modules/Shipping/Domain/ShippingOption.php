<?php

namespace App\Modules\Shipping\Domain;

final class ShippingOption
{
    /** @param array<string, string|null> $name */
    public function __construct(
        public readonly string $methodId,
        public readonly string $code,
        public readonly array $name,
        public readonly string $amount,
        public readonly string $currency,
        public readonly int $extraMinDays,
        public readonly int $extraMaxDays,
        public readonly bool $free,
    ) {}
}
