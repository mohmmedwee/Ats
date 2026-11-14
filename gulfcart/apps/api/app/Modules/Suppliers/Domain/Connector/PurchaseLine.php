<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class PurchaseLine
{
    public function __construct(
        public readonly string $externalProductId,
        public readonly string $externalVariantId,
        public readonly int $quantity,
        public readonly string $expectedUnitCost,
        public readonly string $currency,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
