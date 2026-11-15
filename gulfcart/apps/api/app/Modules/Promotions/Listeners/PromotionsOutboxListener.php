<?php

namespace App\Modules\Promotions\Listeners;

use App\Modules\Promotions\Application\CouponService;
use App\Modules\Promotions\Application\LoyaltyService;
use App\Shared\Outbox\OutboxMessagePublished;

/** Orders that never get paid give their coupon use back; paid orders earn points, refunds take them back. */
final class PromotionsOutboxListener
{
    public function __construct(private readonly CouponService $coupons, private readonly LoyaltyService $loyalty) {}

    public function handle(OutboxMessagePublished $event): void
    {
        if (in_array($event->eventType, ['order.expired', 'order.cancelled'], true)) {
            $this->coupons->release((string) $event->payload['order_id']);
        }
        match ($event->eventType) {
            'order.paid' => $this->loyalty->earnFor((string) $event->payload['order_id']),
            'order.refunded' => $this->loyalty->reverseFor((string) $event->payload['order_id']),
            default => null,
        };
    }
}
