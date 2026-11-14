<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Application\Notifier;
use App\Modules\Orders\Contracts\OrderBook;
use App\Shared\Outbox\OutboxMessagePublished;

/** Maps domain events to customer and operations notifications. */
final class NotificationsOutboxListener
{
    public function __construct(private readonly Notifier $notifier, private readonly OrderBook $orders) {}

    public function handle(OutboxMessagePublished $e): void
    {
        $p = $e->payload;

        match ($e->eventType) {
            'order.paid' => $this->order($p['order_id'], 'order_confirmed', "order_confirmed:{$p['order_id']}"),
            'shipment.updated' => ($p['new'] ?? false) ? $this->order($p['order_id'], 'order_shipped', "order_shipped:{$p['shipment_id']}", [
                'carrier' => (string) ($p['carrier'] ?? ''), 'tracking' => (string) ($p['tracking_number'] ?? ''),
            ]) : null,
            'order.status_changed' => in_array($p['to'], ['delivered', 'cancelled'], true)
                ? $this->order($p['order_id'], 'order_'.$p['to'], "order_{$p['to']}:{$p['order_id']}") : null,
            'refund.succeeded' => $this->order($p['order_id'], 'refund_issued', "refund_issued:{$p['refund_id']}", [
                'amount' => $p['amount'].' '.$p['currency'],
            ]),
            'return.status_changed' => in_array($p['to'], ['approved', 'rejected'], true)
                ? $this->order($p['order_id'], 'return_'.$p['to'], "return_{$p['to']}:{$p['return_id']}") : null,
            'procurement.exception' => $this->notifier->toOperations('ops_procurement_exception', [
                'reason' => (string) $p['reason'], 'supplier_order' => (string) $p['supplier_order_id'],
            ], "ops_exception:{$p['supplier_order_id']}:{$e->outboxId}", 'supplier_order', (string) $p['supplier_order_id']),
            'procurement.manual_required' => $this->notifier->toOperations('ops_manual_procurement', [
                'supplier_order' => (string) $p['supplier_order_id'],
            ], "ops_manual:{$p['supplier_order_id']}", 'supplier_order', (string) $p['supplier_order_id']),
            default => null,
        };
    }

    /** @param array<string, string> $extra */
    private function order(string $orderId, string $template, string $dedupe, array $extra = []): void
    {
        $order = $this->orders->get($orderId);
        if ($order === null) {
            return;
        }
        $this->notifier->toCustomer($order->userId, $template, $extra + [
            'order' => $order->orderNumber, 'total' => $order->grandTotal.' '.$order->currency,
        ], $dedupe, 'order', $orderId, "/account/orders/{$orderId}");
    }
}
