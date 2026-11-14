<?php

namespace App\Modules\Cart\Contracts;

interface AbandonedCarts
{
    /**
     * Signed-in customers' active carts with items, last changed between $minHours and $maxHours ago,
     * not reminded yet.
     *
     * @return list<array{cart_id: string, user_id: string, items: int}>
     */
    public function due(int $minHours, int $maxHours, int $limit = 200): array;

    public function markReminded(string $cartId): void;
}
