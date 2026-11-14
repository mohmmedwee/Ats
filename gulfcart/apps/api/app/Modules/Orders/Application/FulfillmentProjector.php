<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Shared\Outbox\Outbox;

/**
 * Applies supplier-order progress (from Procurement events) to order items and derives the
 * order status: processing → partially_shipped → shipped → delivered. Never moves backwards.
 */
final class FulfillmentProjector
{
    private const ITEM_RANK = ['pending' => 0, 'purchased' => 1, 'shipped' => 2, 'delivered' => 3, 'cancelled' => 9, 'returned' => 9];

    /** @param list<string> $itemIds */
    public function apply(string $orderId, array $itemIds, string $itemStatus): void
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        if ($order === null) {
            return;
        }

        foreach (OrderItem::query()->where('order_id', $orderId)->whereIn('id', $itemIds)->get() as $item) {
            $current = self::ITEM_RANK[$item->fulfillment_status] ?? 0;
            $next = self::ITEM_RANK[$itemStatus] ?? 0;
            $allowed = match ($itemStatus) {
                'cancelled' => $current < 2,                 // shipped items cannot be cancelled
                'returned' => $current >= 2 && $current < 9, // only shipped/delivered items return
                default => $current < 9 && $next > $current, // forward only
            };
            if ($allowed) {
                $item->update(['fulfillment_status' => $itemStatus]);
            }
        }

        $statuses = OrderItem::query()->where('order_id', $orderId)->pluck('fulfillment_status')->all();
        $target = $this->derive($statuses);
        if ($target === null || $target === $order->status) {
            return;
        }

        $from = $order->status;
        $forward = $target === OrderStatus::CANCELLED
            || (OrderStatus::RANK[$target] ?? 0) > (OrderStatus::RANK[$from] ?? 99);
        if ($forward && OrderStatus::canTransition($from, $target)) {
            $order->update(['status' => $target]);
            Outbox::record('order', $order->id, 'order.status_changed', ['order_id' => $order->id, 'from' => $from, 'to' => $target]);
        }
    }

    /** @param list<string> $statuses */
    private function derive(array $statuses): ?string
    {
        $active = array_values(array_filter($statuses, fn ($s) => $s !== 'cancelled'));
        if ($active === []) {
            return OrderStatus::CANCELLED;
        }
        $count = fn (string $s) => count(array_filter($active, fn ($x) => $x === $s));
        $shippedOrMore = $count('shipped') + $count('delivered') + $count('returned');

        return match (true) {
            $count('delivered') + $count('returned') === count($active) => OrderStatus::DELIVERED,
            $shippedOrMore === count($active) => OrderStatus::SHIPPED,
            $shippedOrMore > 0 => OrderStatus::PARTIALLY_SHIPPED,
            $count('purchased') > 0 => OrderStatus::PROCESSING,
            default => null,
        };
    }
}
