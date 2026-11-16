<?php

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Contracts\Offers;
use App\Modules\Promotions\Infrastructure\Models\Offer;
use App\Shared\Money\Decimal;
use Illuminate\Support\Collection;

/**
 * Buy X get Y: eligible units are sorted by price, highest first, and split into groups of X+Y; in each
 * full group the Y cheapest units get the discount. Only the best offer applies (offers do not stack),
 * so the customer always gets the larger saving and the shop never gives two offers on one item.
 */
final class OfferService implements Offers
{
    public function best(array $lines, string $countryCode): ?array
    {
        $best = null;
        foreach ($this->running($countryCode) as $offer) {
            $units = [];
            foreach ($lines as $i => $l) {
                if ($this->covers($offer, $l['product_id'], $l['category_ids'])) {
                    for ($q = 0; $q < $l['quantity']; $q++) {
                        $units[] = ['line' => $i, 'price' => $l['unit_gross']];
                    }
                }
            }
            $group = $offer->buy_qty + $offer->get_qty;
            if (count($units) < $group) {
                continue;
            }
            usort($units, fn ($a, $b) => Decimal::cmp($b['price'], $a['price']));
            $discounts = [];
            $total = '0.00';
            foreach (array_chunk($units, $group) as $chunk) {
                if (count($chunk) < $group) {
                    break;
                }
                foreach (array_slice($chunk, $offer->buy_qty) as $unit) {
                    $off = Decimal::round(Decimal::div(Decimal::mul($unit['price'], (string) $offer->get_percent, 8), '100', 8), 2);
                    $discounts[$unit['line']] = Decimal::add($discounts[$unit['line']] ?? '0.00', $off, 2);
                    $total = Decimal::add($total, $off, 2);
                }
            }
            if ($best === null || Decimal::cmp($total, $best['discount']) > 0) {
                $best = ['offer_id' => $offer->id, 'name' => $offer->name, 'discount' => $total, 'line_discounts' => $discounts];
            }
        }

        return $best !== null && Decimal::cmp($best['discount'], '0') > 0 ? $best : null;
    }

    public function forProduct(string $productId, array $categoryIds, string $countryCode): array
    {
        return $this->running($countryCode)->filter(fn (Offer $o) => $this->covers($o, $productId, $categoryIds))
            ->map(fn (Offer $o) => ['id' => $o->id, 'name' => $o->name, 'buy_qty' => $o->buy_qty, 'get_qty' => $o->get_qty, 'get_percent' => (string) $o->get_percent])
            ->values()->all();
    }

    /** @param list<string> $categoryIds */
    private function covers(Offer $o, string $productId, array $categoryIds): bool
    {
        return $o->scope === 'all' || ($o->scope === 'product' && $o->product_id === $productId) || ($o->scope === 'category' && in_array($o->category_id, $categoryIds, true));
    }

    /** @return Collection<int, Offer> */
    private function running(string $countryCode): Collection
    {
        return Offer::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get()->filter(fn (Offer $o) => $o->countries === [] || in_array($countryCode, $o->countries, true))->values();
    }
}
