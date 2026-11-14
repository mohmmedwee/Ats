<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Procurement\Domain\SupplierOrderStatus;
use App\Modules\Procurement\Infrastructure\Models\ProcurementTask;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;
use App\Modules\Procurement\Jobs\ProcessProcurementTask;
use App\Modules\Suppliers\Contracts\SourcingDirectory;
use App\Shared\Money\Decimal;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * On order.paid: one supplier order (and procurement task) per supplier, holding that
 * supplier's items. Idempotent: an order that already has supplier orders is skipped.
 */
final class OrderSplitter
{
    public function __construct(private readonly OrderBook $orders, private readonly SourcingDirectory $sourcing) {}

    public function split(string $orderId): int
    {
        $created = DB::transaction(function () use ($orderId): array {
            $order = $this->orders->lockForFulfillment($orderId);
            if ($order === null || SupplierOrder::query()->where('order_id', $orderId)->exists()) {
                return [];
            }

            $groups = [];
            foreach ($order->items as $item) {
                $groups[$item->supplierId][] = $item;
            }

            $tasks = [];
            foreach ($groups as $supplierId => $items) {
                $number = DB::selectOne("select nextval('supplier_order_number_seq') as n")->n;
                $so = SupplierOrder::query()->create([
                    'reference' => 'SO-'.$number,
                    'order_id' => $orderId,
                    'supplier_id' => $supplierId,
                    'shipment_group' => $items[0]->shipmentGroup,
                    'status' => SupplierOrderStatus::PENDING,
                    'idempotency_key' => 'so_'.$number.'_v1',
                    'expected_cost' => '0',
                    'cost_currency' => $items[0]->supplierCurrency,
                    'exchange_rate' => $items[0]->exchangeRate,
                    'shipping_address' => $order->shippingAddress,
                ]);

                $expected = '0';
                foreach ($items as $item) {
                    $source = $this->sourcing->source($item->supplierProductId, (string) $item->variantId)
                        ?? throw new RuntimeException("No mapping for order item {$item->id}.");
                    $so->items()->create([
                        'order_item_id' => $item->id,
                        'supplier_product_id' => $item->supplierProductId,
                        'external_product_id' => $source->externalProductId,
                        'external_variant_id' => $source->externalVariantId,
                        'purchase_quantity' => $item->quantity,
                        'purchase_cost' => $item->unitSupplierCost,
                        'shipping_cost' => $item->unitSupplierShipping,
                        'currency' => $item->supplierCurrency,
                    ]);
                    $expected = Decimal::add($expected, Decimal::mul(Decimal::add($item->unitSupplierCost, $item->unitSupplierShipping), (string) $item->quantity));
                }
                $so->update(['expected_cost' => $expected]);

                $tasks[] = ProcurementTask::query()->create(['supplier_order_id' => $so->id, 'status' => 'queued', 'next_attempt_at' => now()]);
                Outbox::record('supplier_order', $so->id, 'supplier_order.created', ['supplier_order_id' => $so->id, 'order_id' => $orderId, 'reference' => $so->reference]);
            }

            return $tasks;
        });

        foreach ($created as $task) {
            ProcessProcurementTask::dispatch($task->id)->afterCommit();
        }

        return count($created);
    }
}
