<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Contracts\PurchaseHistory;
use Illuminate\Support\Facades\DB;

final class EloquentPurchaseHistory implements PurchaseHistory
{
    public function deliveredOrderFor(string $userId, string $productId): ?string
    {
        $id = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $userId)->where('order_items.product_id', $productId)
            ->whereIn('order_items.fulfillment_status', ['delivered', 'returned'])
            ->orderByDesc('orders.placed_at')->value('orders.id');

        return is_string($id) ? $id : null;
    }
}
