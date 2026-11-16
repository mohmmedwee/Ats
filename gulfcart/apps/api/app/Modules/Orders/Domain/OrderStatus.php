<?php

namespace App\Modules\Orders\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * Customer-facing order lifecycle. Money state (partial/full refund) is tracked separately in
 * orders.refund_state. processing → delivered are derived from supplier fulfilment.
 */
final class OrderStatus extends StateMachine
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const PAYMENT_FAILED = 'payment_failed';

    public const EXPIRED = 'expired';

    public const PAID = 'paid';

    /** Cash on delivery: confirmed by the customer, fulfilled now, paid to the courier on delivery. */
    public const COD_CONFIRMED = 'cod_confirmed';

    public const PROCESSING = 'processing';

    public const PARTIALLY_SHIPPED = 'partially_shipped';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected const TRANSITIONS = [
        self::PENDING_PAYMENT => [self::PAID, self::COD_CONFIRMED, self::PAYMENT_FAILED, self::EXPIRED, self::CANCELLED],
        self::PAYMENT_FAILED => [self::PAID, self::COD_CONFIRMED, self::EXPIRED, self::CANCELLED],
        // A capture that arrives after expiry is honoured at the locked price.
        self::EXPIRED => [self::PAID],
        self::PAID => [self::PROCESSING, self::PARTIALLY_SHIPPED, self::SHIPPED, self::CANCELLED],
        self::COD_CONFIRMED => [self::PROCESSING, self::PARTIALLY_SHIPPED, self::SHIPPED, self::CANCELLED],
        self::PROCESSING => [self::PARTIALLY_SHIPPED, self::SHIPPED, self::DELIVERED, self::CANCELLED],
        self::PARTIALLY_SHIPPED => [self::SHIPPED, self::DELIVERED, self::CANCELLED],
        // CANCELLED only for a cash-on-delivery parcel refused at the door (returned to sender).
        self::SHIPPED => [self::DELIVERED, self::CANCELLED],
        self::DELIVERED => [self::COMPLETED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    /** Order of progress, used to ignore out-of-order (backward) fulfilment updates. */
    public const RANK = [
        self::PAID => 1, self::COD_CONFIRMED => 1, self::PROCESSING => 2, self::PARTIALLY_SHIPPED => 3,
        self::SHIPPED => 4, self::DELIVERED => 5, self::COMPLETED => 6,
    ];

    protected static function entity(): string
    {
        return 'order';
    }
}
