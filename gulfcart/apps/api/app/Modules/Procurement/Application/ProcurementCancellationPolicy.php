<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Orders\Contracts\CancellationPolicy;
use App\Modules\Procurement\Domain\SupplierOrderStatus as S;
use App\Modules\Procurement\Infrastructure\Models\ProcurementTask;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;

final class ProcurementCancellationPolicy implements CancellationPolicy
{
    public function __construct(private readonly FulfillmentEvents $events) {}

    /** Caller holds the order row lock (OrderController::cancel). */
    public function cancelBeforePurchase(string $orderId): bool
    {
        $orders = SupplierOrder::query()->where('order_id', $orderId)->lockForUpdate()->get();
        if ($orders->contains(fn ($so) => in_array($so->status, S::COMMITTED, true))) {
            return false;
        }
        foreach ($orders as $so) {
            if ($so->status !== S::CANCELLED) {
                $this->events->move($so, S::CANCELLED);
                ProcurementTask::query()->where('supplier_order_id', $so->id)->update(['status' => 'cancelled']);
            }
        }

        return true;
    }
}
