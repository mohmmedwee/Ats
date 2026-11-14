<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class AvailabilityResult
{
    public function __construct(
        public readonly string $stockStatus,
        public readonly ?string $cost,
        public readonly ?string $currency,
    ) {}

    public function isAvailable(): bool
    {
        return in_array($this->stockStatus, ['in_stock', 'low_stock'], true);
    }
}
