<?php

namespace App\Modules\Procurement\Domain;

use App\Shared\StateMachine\StateMachine;

/** docs/PROCUREMENT_WORKFLOW.md §6.2 */
final class SupplierOrderStatus extends StateMachine
{
    public const PENDING = 'procurement_pending';

    public const SUBMITTING = 'submitting';

    public const UNKNOWN = 'unknown';

    public const MANUAL = 'manual';

    public const PURCHASED = 'purchased';

    public const SUPPLIER_PROCESSING = 'supplier_processing';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const EXCEPTION = 'exception';

    public const CANCEL_REQUESTED = 'cancel_requested';

    public const CANCELLED = 'cancelled';

    protected const TRANSITIONS = [
        self::PENDING => [self::SUBMITTING, self::MANUAL, self::EXCEPTION, self::CANCELLED],
        self::SUBMITTING => [self::PURCHASED, self::UNKNOWN, self::PENDING, self::EXCEPTION, self::MANUAL],
        self::UNKNOWN => [self::PURCHASED, self::PENDING, self::EXCEPTION],
        self::MANUAL => [self::PURCHASED, self::EXCEPTION, self::CANCELLED],
        self::EXCEPTION => [self::PENDING, self::MANUAL, self::PURCHASED, self::CANCELLED],
        self::PURCHASED => [self::SUPPLIER_PROCESSING, self::SHIPPED, self::DELIVERED, self::CANCEL_REQUESTED, self::CANCELLED],
        self::SUPPLIER_PROCESSING => [self::SHIPPED, self::DELIVERED, self::CANCEL_REQUESTED, self::CANCELLED],
        self::CANCEL_REQUESTED => [self::CANCELLED, self::SUPPLIER_PROCESSING, self::SHIPPED],
        self::SHIPPED => [self::DELIVERED],
        self::DELIVERED => [],
        self::CANCELLED => [],
    ];

    /** Fulfilment state of the order items for each supplier-order state. */
    public const ITEM_STATUS = [
        self::PURCHASED => 'purchased',
        self::SUPPLIER_PROCESSING => 'purchased',
        self::SHIPPED => 'shipped',
        self::DELIVERED => 'delivered',
        self::CANCELLED => 'cancelled',
    ];

    /** States in which the supplier may have (or has) the order: never cancel locally. */
    public const COMMITTED = [self::SUBMITTING, self::UNKNOWN, self::PURCHASED, self::SUPPLIER_PROCESSING, self::SHIPPED, self::DELIVERED, self::CANCEL_REQUESTED];

    protected static function entity(): string
    {
        return 'supplier_order';
    }
}
