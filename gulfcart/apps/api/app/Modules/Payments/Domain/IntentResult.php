<?php

namespace App\Modules\Payments\Domain;

final class IntentResult
{
    /** status: requires_action (customer must complete on the provider page) | authorized | captured */
    public function __construct(
        public readonly string $providerReference,
        public readonly string $status,
        public readonly ?string $redirectUrl,
    ) {}
}
