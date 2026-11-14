<?php

namespace App\Modules\Suppliers\Events;

/** Supplier cost, stock, mapping or authorization changed for a product. Pricing reprices on this. */
final class SourcingChanged
{
    public function __construct(public readonly string $productId) {}
}
