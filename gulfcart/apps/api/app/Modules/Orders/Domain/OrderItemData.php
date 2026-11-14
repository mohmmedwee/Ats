<?php

namespace App\Modules\Orders\Domain;

final class OrderItemData
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public readonly string $id,
        public readonly ?string $variantId,
        public readonly string $supplierId,
        public readonly string $supplierProductId,
        public readonly int $quantity,
        public readonly string $unitGross,
        public readonly string $lineTotal,
        public readonly string $unitSupplierCost,
        public readonly string $unitSupplierShipping,
        public readonly string $supplierCurrency,
        public readonly string $exchangeRate,
        public readonly int $shipmentGroup,
        public readonly string $fulfillmentStatus,
        public readonly int $refundedQuantity,
        public readonly array $snapshot,
        public readonly string $unitNet = '0',
        public readonly string $unitTax = '0',
        public readonly string $vatRate = '0',
    ) {}
}
