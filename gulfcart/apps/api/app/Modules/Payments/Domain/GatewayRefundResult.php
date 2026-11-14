<?php

namespace App\Modules\Payments\Domain;

final class GatewayRefundResult
{
    /** status: succeeded | pending | failed */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference,
        public readonly ?string $failureReason = null,
    ) {}
}
