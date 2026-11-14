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
use App\Modules\Suppliers\Domain\Connector\TransientConnectorException;
use App\Modules\Suppliers\Domain\Connector\UnknownOutcomeException;
use App\Modules\Suppliers\Infrastructure\Models\MockSupplierOrder;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Shared\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Simulated supplier for development, tests and staging. Behaviour is configured in
 * suppliers.metadata.mock:
 *   cost_change_percent  (number)  applied to every cost the "remote" reports
 *   out_of_stock         (list)    external variant IDs reported out of stock
 *   purchase_mode        (string)  accept | reject | transient | unknown_then_accept | unknown_then_missing
 * Orders are idempotent per key, like a well-behaved real supplier API.
 */
final class MockSupplierConnector implements SupplierConnector
{
    use LocalCatalogReads;

    public function __construct(private readonly Supplier $supplierModel) {}

    protected function supplier(): Supplier
    {
        return $this->supplierModel;
    }

    /** @return array<string, mixed> */
    private function mock(): array
    {
        return (array) ($this->supplierModel->metadata['mock'] ?? []);
    }

    protected function adjustCost(string $cost): string
    {
        $pct = (string) ($this->mock()['cost_change_percent'] ?? '0');

        return Decimal::mul($cost, Decimal::add('1', Decimal::div($pct, '100', 6), 6));
    }

    protected function adjustStock(string $externalVariantId, string $stock): string
    {
        return in_array($externalVariantId, (array) ($this->mock()['out_of_stock'] ?? []), true) ? 'out_of_stock' : $stock;
    }

    public function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(true, true, true, true, true);
    }

    public function createOrder(PurchaseRequest $request, string $idempotencyKey): PurchaseResult
    {
        $existing = MockSupplierOrder::query()->where('supplier_id', $this->supplierModel->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $this->accepted($existing, true);
        }

        $mode = (string) ($this->mock()['purchase_mode'] ?? 'accept');
        if ($mode === 'transient') {
            throw new TransientConnectorException('Mock supplier: service unavailable (request not processed).');
        }
        if ($mode === 'reject') {
            return new PurchaseResult(PurchaseResult::REJECTED, null, null, null, ['error' => 'rejected_by_mock'], 'Mock supplier rejected the order.');
        }

        $quote = $this->getQuote($request->lines);
        if ($mode === 'unknown_then_missing') {
            throw new UnknownOutcomeException('Mock supplier: timeout after send (order not created).');
        }

        $order = DB::transaction(fn () => MockSupplierOrder::query()->create([
            'supplier_id' => $this->supplierModel->id,
            'idempotency_key' => $idempotencyKey,
            'external_order_id' => 'MOCK-'.Str::upper(Str::random(10)),
            'status' => 'processing',
            'items' => array_map(fn ($l) => $l->toArray(), $request->lines),
            'total_cost' => Decimal::add($quote->itemsTotal, $quote->shippingTotal),
            'currency' => $quote->currency,
            'events' => [['code' => 'ORDER_RECEIVED', 'description' => 'Order received', 'location' => null, 'occurred_at' => now()->toIso8601String()]],
        ]));

        if ($mode === 'unknown_then_accept') {
            throw new UnknownOutcomeException('Mock supplier: timeout after send (order was created).');
        }

        return $this->accepted($order, false);
    }

    public function findOrderByReference(string $idempotencyKey): ?SupplierOrderStatus
    {
        $order = MockSupplierOrder::query()->where('supplier_id', $this->supplierModel->id)->where('idempotency_key', $idempotencyKey)->first();

        return $order === null ? null : new SupplierOrderStatus($order->external_order_id, $order->status, $order->total_cost, $order->currency);
    }

    public function getOrderStatus(string $externalOrderId): SupplierOrderStatus
    {
        $order = $this->order($externalOrderId);

        return new SupplierOrderStatus($order->external_order_id, $order->status, $order->total_cost, $order->currency);
    }

    public function getTracking(string $externalOrderId): TrackingInfo
    {
        $order = $this->order($externalOrderId);

        return new TrackingInfo(
            $order->carrier,
            $order->tracking_number,
            $order->tracking_number ? "https://tracking.example.test/{$order->tracking_number}" : null,
            $order->status,
            $order->events,
        );
    }

    public function cancelOrder(string $externalOrderId): CancellationResult
    {
        $order = $this->order($externalOrderId);
        if (in_array($order->status, ['shipped', 'delivered'], true)) {
            return new CancellationResult(false, 'Already shipped.');
        }
        $order->update(['status' => 'cancelled']);

        return new CancellationResult(true);
    }

    /** Test/staging helper: move a mock order forward (processing → shipped → delivered). */
    public function advance(string $externalOrderId, string $status): void
    {
        $order = $this->order($externalOrderId);
        $events = $order->events;
        $events[] = ['code' => strtoupper($status), 'description' => ucfirst($status), 'location' => $status === 'delivered' ? 'Customer address' : 'Origin hub', 'occurred_at' => now()->toIso8601String()];
        $order->update([
            'status' => $status,
            'carrier' => $order->carrier ?? 'MockExpress',
            'tracking_number' => $order->tracking_number ?? 'MX'.Str::upper(Str::random(10)),
            'events' => $events,
        ]);
    }

    private function order(string $externalOrderId): MockSupplierOrder
    {
        return MockSupplierOrder::query()->where('supplier_id', $this->supplierModel->id)->where('external_order_id', $externalOrderId)->first()
            ?? throw new ConnectorException("Unknown mock order {$externalOrderId}.");
    }

    private function accepted(MockSupplierOrder $order, bool $replayed): PurchaseResult
    {
        return new PurchaseResult(PurchaseResult::ACCEPTED, $order->external_order_id, $order->total_cost, $order->currency, [
            'external_order_id' => $order->external_order_id, 'status' => $order->status, 'replayed' => $replayed,
        ]);
    }
}
