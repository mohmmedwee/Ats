<?php

namespace App\Modules\Checkout\Contracts;

use App\Modules\Checkout\Domain\CheckoutSessionData;

interface CheckoutSessions
{
    /** Locks the session row for update; call inside a transaction. */
    public function lockForOrder(string $sessionId, string $userId): ?CheckoutSessionData;

    public function markCompleted(string $sessionId): void;
}
