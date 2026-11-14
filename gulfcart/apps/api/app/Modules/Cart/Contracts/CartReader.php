<?php

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\Domain\CartData;

interface CartReader
{
    public function activeCartFor(string $userId, string $countryId): ?CartData;

    public function markConverted(string $cartId): void;
}
