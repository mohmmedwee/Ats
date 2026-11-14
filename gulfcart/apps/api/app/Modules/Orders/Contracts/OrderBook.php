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

    /**
     * Applies a succeeded refund to the order totals.
     *
     * @param  array<string, int>  $itemQuantities  order item ID => refunded quantity
     */
    public function recordRefund(string $orderId, string $amount, array $itemQuantities = []): void;
}
