<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class PurchaseRequest
{
    /**
     * @param  list<PurchaseLine>  $lines
     * @param  array<string, string|null>  $shippingAddress  name, phone, line1, line2, city, state, postal_code, country
     */
    public function __construct(
        public readonly string $reference,
        public readonly array $lines,
        public readonly array $shippingAddress,
    ) {}
}
