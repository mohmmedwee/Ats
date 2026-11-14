<?php

namespace App\Modules\Orders\Domain;

use App\Shared\Money\Decimal;
use Brick\Math\RoundingMode;

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
        public readonly string $lineDiscount = '0',
    ) {}

    public function isDiscounted(): bool
    {
        return Decimal::cmp($this->lineDiscount, '0') > 0;
    }

    /** What the customer actually paid per unit after any order discount (rounded down to the cent). */
    public function paidUnitGross(): string
    {
        if (! $this->isDiscounted()) {
            return $this->unitGross;
        }
        $paid = Decimal::sub($this->lineTotal, $this->lineDiscount, 2);

        return Decimal::round(Decimal::div($paid, (string) $this->quantity, 8), 2, RoundingMode::Down);
    }
}
