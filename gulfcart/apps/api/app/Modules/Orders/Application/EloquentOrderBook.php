<?php

namespace App\Modules\Orders\Application;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Orders\Domain\OrderData;
use App\Modules\Orders\Domain\OrderItemData;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use App\Shared\Outbox\Outbox;

final class EloquentOrderBook implements OrderBook
{
    public function __construct(private readonly CountryDirectory $countries) {}

    public function get(string $orderId): ?OrderData
    {
        $order = Order::query()->with('items')->find($orderId);

        return $order === null ? null : $this->toData($order);
    }

    public function lockForFulfillment(string $orderId): ?OrderData
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        if ($order === null || ! in_array($order->status, [OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::PARTIALLY_SHIPPED], true)) {
            return null;
        }

        return $this->toData($order->load('items'));
    }

    public function recordPayment(string $orderId, string $paymentId, string $amount): void
    {
        $order = $this->lock($orderId);
        $paid = Decimal::add($order->paid_total, $amount, 2);
        $order->paid_total = $paid;

        if (Decimal::cmp($paid, $order->grand_total) >= 0 && in_array($order->status, [OrderStatus::PENDING_PAYMENT, OrderStatus::PAYMENT_FAILED, OrderStatus::EXPIRED], true)) {
            OrderStatus::assertTransition($order->status, OrderStatus::PAID);
            $order->status = OrderStatus::PAID;
            $order->paid_at = now();
            $order->save();
            Outbox::record('order', $order->id, 'order.paid', ['order_id' => $order->id, 'order_number' => $order->order_number, 'payment_id' => $paymentId]);

            return;
        }
        $order->save();
    }

    public function recordPaymentFailure(string $orderId): void
    {
        $order = $this->lock($orderId);
        if ($order->status === OrderStatus::PENDING_PAYMENT) {
            $order->update(['status' => OrderStatus::PAYMENT_FAILED]);
            Outbox::record('order', $order->id, 'order.payment_failed', ['order_id' => $order->id]);
        }
    }

    public function recordRefund(string $orderId, string $amount, array $itemQuantities = []): void
    {
        $order = $this->lock($orderId);
        $refunded = Decimal::add($order->refunded_total, $amount, 2);
        if (Decimal::cmp($refunded, $order->paid_total) > 0) {
            throw new ApiException('REFUND_EXCEEDS_CAPTURED', 422);
        }
        $order->refunded_total = $refunded;
        $order->refund_state = Decimal::cmp($refunded, $order->paid_total) >= 0 ? 'full' : 'partial';
        $order->save();

        foreach ($itemQuantities as $itemId => $qty) {
            OrderItem::query()->where('order_id', $order->id)->whereKey($itemId)
                ->whereRaw('refunded_quantity + ? <= quantity', [$qty])
                ->increment('refunded_quantity', $qty);
        }
        Outbox::record('order', $order->id, 'order.refunded', ['order_id' => $order->id, 'amount' => $amount, 'refund_state' => $order->refund_state]);
    }

    private function lock(string $orderId): Order
    {
        return Order::query()->whereKey($orderId)->lockForUpdate()->first() ?? throw new ApiException('NOT_FOUND', 404);
    }

    public function toData(Order $o): OrderData
    {
        $code = '';
        foreach ($this->countries->active() as $c) {
            $code = $c->id === $o->country_id ? $c->code : $code;
        }

        return new OrderData(
            $o->id, $o->order_number, $o->user_id, $o->status, $code, $o->currency,
            (string) $o->grand_total, (string) $o->paid_total, (string) $o->refunded_total, $o->locale,
            $o->shipping_address_snapshot,
            $o->items->map(fn (OrderItem $i) => new OrderItemData(
                $i->id, $i->variant_id, $i->supplier_id, $i->supplier_product_id, $i->quantity,
                (string) $i->unit_gross_price, (string) $i->line_total, (string) $i->unit_supplier_cost,
                (string) $i->unit_supplier_shipping, $i->supplier_currency, (string) $i->exchange_rate,
                $i->shipment_group, $i->fulfillment_status, $i->refunded_quantity, $i->product_snapshot,
                (string) $i->unit_net_price, (string) $i->unit_tax, (string) $i->vat_rate, (string) $i->line_discount,
            ))->all(),
            (string) $o->shipping_total, (string) $o->customs_total, (string) $o->tax_total, $o->paid_at?->toIso8601String(),
        );
    }
}
