<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class SupplierOrderStatus
{
    /** status: pending | processing | shipped | delivered | cancelled */
    public function __construct(
        public readonly string $externalOrderId,
        public readonly string $status,
        public readonly ?string $totalCost = null,
        public readonly ?string $currency = null,
    ) {}
}
