<?php

namespace App\Modules\Cart\Domain;

final class CartLine
{
    public function __construct(public readonly string $itemId, public readonly string $variantId, public readonly int $quantity) {}
}
