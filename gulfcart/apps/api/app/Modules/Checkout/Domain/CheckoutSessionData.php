<?php

namespace App\Modules\Checkout\Domain;

/** A locked quote handed to the Orders module. */
final class CheckoutSessionData
{
    /** @param array<string, mixed> $quote full internal quote (includes costs and supplier references) */
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $cartId,
        public readonly string $countryId,
        public readonly string $countryCode,
        public readonly string $currency,
        public readonly string $addressId,
        public readonly array $quote,
        public readonly bool $usable,
    ) {}
}
