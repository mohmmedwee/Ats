<?php

namespace App\Modules\Pricing\Domain;

final class PricingResult
{
    public function __construct(
        public readonly string $currency,
        public readonly string $variableCost,
        public readonly string $netPrice,
        public readonly string $vatAmount,
        public readonly string $grossPrice,
        public readonly string $paymentFee,
        public readonly string $contribution,
        public readonly string $marginPercent,
        public readonly string $effectiveFxRate,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
