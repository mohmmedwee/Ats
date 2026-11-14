<?php

namespace App\Modules\Promotions\Listeners;

use App\Modules\Promotions\Application\CouponService;
use App\Shared\Outbox\OutboxMessagePublished;

/** Orders that never get paid give their coupon use back. */
final class PromotionsOutboxListener
{
    public function __construct(private readonly CouponService $coupons) {}

    public function handle(OutboxMessagePublished $event): void
    {
        if (in_array($event->eventType, ['order.expired', 'order.cancelled'], true)) {
            $this->coupons->release((string) $event->payload['order_id']);
        }
    }
}
