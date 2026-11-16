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
        public readonly ?string $customerName = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $countryCode = null,
        /** @var array<string, string|null> delivery address (for instalment providers' risk checks) */
        public readonly array $address = [],
        public readonly string $locale = 'en',
    ) {}
}
