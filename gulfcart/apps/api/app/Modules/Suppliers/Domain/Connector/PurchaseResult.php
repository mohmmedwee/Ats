<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class PurchaseResult
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const MANUAL_REQUIRED = 'manual_required';

    /** @param array<string, mixed> $raw redacted supplier response */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $externalOrderId,
        public readonly ?string $totalCost,
        public readonly ?string $currency,
        public readonly array $raw = [],
        public readonly ?string $reason = null,
    ) {}
}
