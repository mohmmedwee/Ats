<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Domain\WebhookEvent;

/**
 * Gateways whose payment is finished by the merchant after the customer comes back (PayPal: the order is
 * approved by the buyer, then captured by us). Returns the resulting event, or null when nothing to do yet.
 */
interface ConfirmsOnReturn
{
    public function confirm(string $providerReference): ?WebhookEvent;
}
