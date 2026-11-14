<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class SupplierQuote
{
    /** @param array<string, string> $unitCosts keyed by external variant ID */
    public function __construct(
        public readonly array $unitCosts,
        public readonly string $itemsTotal,
        public readonly string $shippingTotal,
        public readonly string $currency,
    ) {}
}
