<?php

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Domain\OrderData;

/**
 * Orders API for Payments, Procurement and Returns. Money-changing methods must be
 * called inside the caller's database transaction; they lock the order row.
 */
interface OrderBook
{
    public function get(string $orderId): ?OrderData;

    /**
     * Locks the order row (call inside a transaction) and returns it only while it can be
     * fulfilled (paid or processing). Serializes procurement against customer cancellation.
     */
    public function lockForFulfillment(string $orderId): ?OrderData;

    /** Applies a captured payment; marks the order paid when fully paid (outbox: order.paid). */
    public function recordPayment(string $orderId, string $paymentId, string $amount): void;

    public function recordPaymentFailure(string $orderId): void;

    /** Cash on delivery confirmed by the customer: fulfil now (outbox: order.cod_confirmed). */
    public function confirmCashOnDelivery(string $orderId): void;

    /** The courier collected the cash (outbox: order.cod_collected). */
    public function recordCashCollected(string $orderId, string $paymentId, string $amount): void;

    /** The customer refused the parcel: the order is cancelled (outbox: order.cancelled). */
    public function recordCashRefused(string $orderId): void;

    /**
     * Applies a succeeded refund to the order totals.
     *
     * @param  array<string, int>  $itemQuantities  order item ID => refunded quantity
     */
    public function recordRefund(string $orderId, string $amount, array $itemQuantities = []): void;
}
