<?php

namespace App\Modules\Payments\Domain;

final class IntentRequest
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $orderNumber,
        public readonly string $returnUrl,
    ) {}
}
