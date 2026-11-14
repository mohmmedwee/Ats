<?php

namespace App\Modules\Suppliers\Contracts;

use App\Modules\Suppliers\Domain\Connector\AvailabilityResult;
use App\Modules\Suppliers\Domain\Connector\CancellationResult;
use App\Modules\Suppliers\Domain\Connector\ConnectorCapabilities;
use App\Modules\Suppliers\Domain\Connector\ExternalProduct;
use App\Modules\Suppliers\Domain\Connector\PurchaseLine;
use App\Modules\Suppliers\Domain\Connector\PurchaseRequest;
use App\Modules\Suppliers\Domain\Connector\PurchaseResult;
use App\Modules\Suppliers\Domain\Connector\SupplierOrderStatus;
use App\Modules\Suppliers\Domain\Connector\SupplierQuote;
use App\Modules\Suppliers\Domain\Connector\TrackingInfo;

/**
 * Every supplier integration implements this contract (ADR-006, PROCUREMENT_WORKFLOW.md §5).
 * Methods throw TransientConnectorException (safe to retry), UnknownOutcomeException
 * (reconcile before retrying) or ConnectorException (permanent).
 */
interface SupplierConnector
{
    public function capabilities(): ConnectorCapabilities;

    public function getProduct(string $externalProductId): ExternalProduct;

    public function checkAvailability(string $externalProductId, string $externalVariantId): AvailabilityResult;

    /** @param list<PurchaseLine> $lines */
    public function getQuote(array $lines): SupplierQuote;

    public function createOrder(PurchaseRequest $request, string $idempotencyKey): PurchaseResult;

    /** Reconciliation after an unknown outcome. Null when the supplier has no such order. */
    public function findOrderByReference(string $idempotencyKey): ?SupplierOrderStatus;

    public function getOrderStatus(string $externalOrderId): SupplierOrderStatus;

    public function getTracking(string $externalOrderId): TrackingInfo;

    public function cancelOrder(string $externalOrderId): CancellationResult;
}
