<?php

namespace App\Modules\Suppliers\Application\Connectors;

use App\Modules\Suppliers\Contracts\SupplierConnector;
use App\Modules\Suppliers\Domain\Connector\CancellationResult;
use App\Modules\Suppliers\Domain\Connector\ConnectorCapabilities;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Domain\Connector\PurchaseRequest;
use App\Modules\Suppliers\Domain\Connector\PurchaseResult;
use App\Modules\Suppliers\Domain\Connector\SupplierOrderStatus;
use App\Modules\Suppliers\Domain\Connector\TrackingInfo;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;

/**
 * Fallback for suppliers without an authorized API: purchases are placed by an
 * operator from the admin procurement queue, then recorded.
 */
final class ManualSupplierConnector implements SupplierConnector
{
    use LocalCatalogReads;

    public function __construct(private readonly Supplier $supplierModel) {}

    protected function supplier(): Supplier
    {
        return $this->supplierModel;
    }

    public function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(false, false, false, false, false);
    }

    public function createOrder(PurchaseRequest $request, string $idempotencyKey): PurchaseResult
    {
        return new PurchaseResult(PurchaseResult::MANUAL_REQUIRED, null, null, null, [], 'Manual procurement supplier.');
    }

    public function findOrderByReference(string $idempotencyKey): ?SupplierOrderStatus
    {
        return null;
    }

    public function getOrderStatus(string $externalOrderId): SupplierOrderStatus
    {
        throw new ConnectorException('Manual suppliers have no status API.');
    }

    public function getTracking(string $externalOrderId): TrackingInfo
    {
        throw new ConnectorException('Manual suppliers have no tracking API.');
    }

    public function cancelOrder(string $externalOrderId): CancellationResult
    {
        return new CancellationResult(false, 'Cancel with the supplier manually.');
    }
}
