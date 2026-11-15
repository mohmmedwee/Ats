<?php

namespace App\Modules\Pricing\Domain;

/** Current price of a variant in a country. Cost fields are internal and never exposed to customers. */
final class VariantPrice
{
    /** @param array<string, mixed> $calculation */
    public function __construct(
        public readonly string $variantId,
        public readonly string $countryCode,
        public readonly string $currency,
        public readonly string $gross,
        public readonly string $net,
        public readonly string $vat,
        public readonly string $vatRate,
        public readonly string $supplierProductId,
        public readonly string $variableCost,
        public readonly ?string $exchangeRateId,
        public readonly string $exchangeRate,
        public readonly array $calculation,
        /** Regular price when a flash sale lowered this one. */
        public readonly ?string $compareAt = null,
        public readonly ?string $saleEndsAt = null,
    ) {}
}
