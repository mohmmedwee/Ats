<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class ConnectorCapabilities
{
    public function __construct(
        public readonly bool $automatedPurchasing,
        public readonly bool $idempotentOrders,
        public readonly bool $cancellation,
        public readonly bool $tracking,
        public readonly bool $catalogSync,
    ) {}
}
