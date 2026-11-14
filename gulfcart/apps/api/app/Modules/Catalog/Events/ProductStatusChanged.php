<?php

namespace App\Modules\Catalog\Events;

final class ProductStatusChanged
{
    public function __construct(public readonly string $productId, public readonly string $from, public readonly string $to) {}
}
