<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Outbox\Outbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireUnpaidOrdersCommand extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Expire orders whose payment window has passed';

    public function handle(): int
    {
        $count = 0;
        Order::query()->whereIn('status', [OrderStatus::PENDING_PAYMENT, OrderStatus::PAYMENT_FAILED])
            ->where('payment_due_at', '<', now())->pluck('id')
            ->each(function (string $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $order = Order::query()->whereKey($id)->lockForUpdate()->first();
                    if ($order !== null && in_array($order->status, [OrderStatus::PENDING_PAYMENT, OrderStatus::PAYMENT_FAILED], true)) {
                        $order->update(['status' => OrderStatus::EXPIRED]);
                        Outbox::record('order', $order->id, 'order.expired', ['order_id' => $order->id]);
                        $count++;
                    }
                });
            });
        $this->info("Expired {$count} order(s).");

        return self::SUCCESS;
    }
}
