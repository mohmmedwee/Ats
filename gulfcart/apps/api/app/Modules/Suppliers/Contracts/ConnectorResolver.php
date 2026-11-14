<?php

namespace App\Modules\Suppliers\Contracts;

interface ConnectorResolver
{
    public function forSupplier(string $supplierId): SupplierConnector;
}
