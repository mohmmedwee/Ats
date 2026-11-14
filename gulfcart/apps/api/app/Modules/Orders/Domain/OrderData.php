<?php

namespace App\Modules\Orders\Domain;

final class OrderData
{
    /**
     * @param  list<OrderItemData>  $items
     * @param  array<string, string|null>  $shippingAddress
     */
    public function __construct(
        public readonly string $id,
        public readonly string $orderNumber,
        public readonly string $userId,
        public readonly string $status,
        public readonly string $countryCode,
        public readonly string $currency,
        public readonly string $grandTotal,
        public readonly string $paidTotal,
        public readonly string $refundedTotal,
        public readonly string $locale,
        public readonly array $shippingAddress,
        public readonly array $items,
        public readonly string $shippingTotal = '0',
        public readonly string $customsTotal = '0',
        public readonly string $taxTotal = '0',
        public readonly ?string $paidAt = null,
    ) {}

    public function item(string $id): ?OrderItemData
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }
}
