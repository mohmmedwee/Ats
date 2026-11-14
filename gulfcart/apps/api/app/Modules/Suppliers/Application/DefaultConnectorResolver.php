<?php

namespace App\Modules\Suppliers\Application;

use App\Modules\Suppliers\Application\Connectors\ManualSupplierConnector;
use App\Modules\Suppliers\Application\Connectors\MockSupplierConnector;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Contracts\SupplierConnector;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;

/**
 * Resolves suppliers.connector_type to an implementation. Authorized connectors are
 * registered in config('gulfcart.supplier_connectors') as type => class; each class
 * receives the Supplier model in its constructor.
 */
final class DefaultConnectorResolver implements ConnectorResolver
{
    public function forSupplier(string $supplierId): SupplierConnector
    {
        $supplier = Supplier::query()->withTrashed()->findOrFail($supplierId);

        return match ($supplier->connector_type) {
            'manual' => new ManualSupplierConnector($supplier),
            'mock' => app()->environment('production') && ! config('gulfcart.allow_mock_supplier_in_production')
                ? throw new ConnectorException('The mock connector is disabled in production.')
                : new MockSupplierConnector($supplier),
            default => $this->configured($supplier),
        };
    }

    private function configured(Supplier $supplier): SupplierConnector
    {
        $class = config('gulfcart.supplier_connectors.'.$supplier->connector_type);
        if (! is_string($class) || ! is_a($class, SupplierConnector::class, true)) {
            throw new ConnectorException("No connector registered for type [{$supplier->connector_type}].");
        }

        return new $class($supplier);
    }
}
