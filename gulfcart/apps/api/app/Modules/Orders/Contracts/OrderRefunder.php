<?php

namespace App\Modules\Orders\Contracts;

/** Implemented by Payments. Used by Orders (cancellation), Procurement (failed purchases) and Returns. */
interface OrderRefunder
{
    /**
     * Requests a refund. System refunds ($actorId null) are approved automatically.
     *
     * @param  array<string, int>  $itemQuantities
     * @return string refund ID
     */
    public function refund(string $orderId, string $amount, string $reasonCode, ?string $actorId, array $itemQuantities = [], ?string $returnId = null): string;
}
