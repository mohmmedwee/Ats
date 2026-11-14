<?php

namespace App\Modules\Cart\Domain;

final class CartData
{
    /** @param list<CartLine> $lines */
    public function __construct(
        public readonly string $id,
        public readonly ?string $userId,
        public readonly string $countryId,
        public readonly string $currency,
        public readonly array $lines,
    ) {}
}
