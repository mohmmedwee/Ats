<?php

namespace App\Modules\Payments\Domain;

final class WebhookEvent
{
    public const PAYMENT_CAPTURED = 'payment.captured';

    public const PAYMENT_FAILED = 'payment.failed';

    public const REFUND_SUCCEEDED = 'refund.succeeded';

    public const REFUND_FAILED = 'refund.failed';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $providerReference,
        public readonly ?string $amount,
        public readonly ?string $currency,
        public readonly array $payload,
        public readonly ?string $failureCode = null,
        public readonly ?string $method = null,
    ) {}
}
