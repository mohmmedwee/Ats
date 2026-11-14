<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class ExternalVariant
{
    public function __construct(
        public readonly string $externalVariantId,
        public readonly string $cost,
        public readonly string $currency,
        public readonly string $stockStatus,
    ) {}
}
