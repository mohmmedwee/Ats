<?php

namespace App\Modules\Pricing\Domain;

/** All amounts are decimal strings. Costs are in the cost currency; allocation and fees in the sale currency. */
final class PricingInput
{
    public function __construct(
        public readonly string $unitCost,
        public readonly string $shippingCost,
        public readonly string $costCurrency,
        public readonly string $saleCurrency,
        public readonly string $fxRate,
        public readonly string $fxBufferPercent,
        public readonly string $importVatPercent,
        public readonly string $operationalAllocation,
        public readonly string $vatPercent,
        public readonly string $feePercent,
        public readonly string $feeFixed,
        public readonly ResolvedRule $rule,
        public readonly string $rounding,
    ) {}
}
