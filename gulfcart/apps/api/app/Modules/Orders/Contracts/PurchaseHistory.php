<?php

namespace App\Modules\Orders\Contracts;

interface PurchaseHistory
{
    /** An order of this customer in which the product was delivered, or null (verified-purchase reviews). */
    public function deliveredOrderFor(string $userId, string $productId): ?string;
}
