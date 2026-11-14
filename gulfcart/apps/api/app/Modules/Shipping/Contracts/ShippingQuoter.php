<?php

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\Domain\ShippingOption;

/** Customer-facing shipping fee (VAT inclusive), charged once per order. */
interface ShippingQuoter
{
    /** @return list<ShippingOption> */
    public function options(string $countryCode, string $subtotalGross, int $totalWeightGrams): array;

    /** Lowest free-delivery threshold among active methods (VAT inclusive), or null. */
    public function freeShippingThreshold(string $countryCode): ?string;

    public function option(string $countryCode, string $code, string $subtotalGross, int $totalWeightGrams): ?ShippingOption;
}
