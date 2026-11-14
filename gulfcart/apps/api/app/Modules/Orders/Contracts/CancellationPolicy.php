<?php

namespace App\Modules\Orders\Contracts;

/** Implemented by Procurement: cancels supplier orders that have not been purchased yet. */
interface CancellationPolicy
{
    /** True when nothing was purchased and every supplier order is now cancelled. */
    public function cancelBeforePurchase(string $orderId): bool;
}
