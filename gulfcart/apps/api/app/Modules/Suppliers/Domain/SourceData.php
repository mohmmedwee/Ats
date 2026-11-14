<?php

namespace App\Modules\Suppliers\Domain;

/** A purchasable source for a variant. Internal: never exposed to customers. */
final class SourceData
{
    public function __construct(
        public readonly string $supplierId,
        public readonly string $supplierCode,
        public readonly string $connectorType,
        public readonly string $supplierProductId,
        public readonly string $variantId,
        public readonly string $externalProductId,
        public readonly string $externalVariantId,
        public readonly string $unitCost,
        public readonly string $costCurrency,
        public readonly string $shippingCost,
        public readonly string $stockStatus,
        public readonly int $priority,
        public readonly ?int $deliveryMinDays,
        public readonly ?int $deliveryMaxDays,
        public readonly bool $allowsApiPurchasing,
    ) {}

    public function inStock(): bool
    {
        return in_array($this->stockStatus, ['in_stock', 'low_stock'], true);
    }
}
