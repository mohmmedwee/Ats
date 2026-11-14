<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class TrackingInfo
{
    /** @param list<array{code: string, description: string, location: string|null, occurred_at: string}> $events */
    public function __construct(
        public readonly ?string $carrier,
        public readonly ?string $trackingNumber,
        public readonly ?string $trackingUrl,
        public readonly string $status,
        public readonly array $events,
    ) {}
}
