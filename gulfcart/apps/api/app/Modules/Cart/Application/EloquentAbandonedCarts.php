<?php

namespace App\Modules\Cart\Application;

use App\Modules\Cart\Contracts\AbandonedCarts;
use Illuminate\Support\Facades\DB;

final class EloquentAbandonedCarts implements AbandonedCarts
{
    public function due(int $minHours, int $maxHours, int $limit = 200): array
    {
        $rows = DB::table('carts')
            ->join('cart_items', 'cart_items.cart_id', '=', 'carts.id')
            ->where('carts.status', 'active')->whereNotNull('carts.user_id')->whereNull('carts.reminded_at')
            ->groupBy('carts.id', 'carts.user_id', 'carts.updated_at')
            ->havingRaw('greatest(carts.updated_at, max(cart_items.updated_at)) between ? and ?', [now()->subHours($maxHours), now()->subHours($minHours)])
            ->selectRaw('carts.id as cart_id, carts.user_id, sum(cart_items.quantity) as items')
            ->limit($limit)->get();

        return $rows->map(fn ($r) => ['cart_id' => (string) $r->cart_id, 'user_id' => (string) $r->user_id, 'items' => (int) $r->items])->all();
    }

    public function markReminded(string $cartId): void
    {
        DB::table('carts')->where('id', $cartId)->update(['reminded_at' => now()]);
    }
}
