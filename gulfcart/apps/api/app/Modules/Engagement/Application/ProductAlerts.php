<?php

namespace App\Modules\Engagement\Application;

use App\Modules\Catalog\Contracts\CatalogPricing;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Contracts\CatalogStock;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Engagement\Infrastructure\Models\ProductAlert;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Back-in-stock and price-drop alerts. A periodic check compares live stock and prices with what the
 * customer saw; each alert fires once (outbox: engagement.product_alert → Notifications).
 */
final class ProductAlerts
{
    public function __construct(private readonly CatalogReader $catalog, private readonly CatalogStock $stock, private readonly CatalogPricing $pricing, private readonly CountryDirectory $countries) {}

    public function subscribe(string $userId, string $productId, ?string $variantId, string $type, string $countryCode): ProductAlert
    {
        $variants = $this->catalog->variantIdsOf($productId);
        if ($variants === [] || ($variantId !== null && ! in_array($variantId, $variants, true))) {
            throw new ApiException('NOT_FOUND', 404);
        }
        $price = $this->price($productId, $variantId, $countryCode);
        if ($type === 'price_drop' && $price === null) {
            throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['type' => ['This product has no price in your country.']]]);
        }

        return ProductAlert::query()->firstOrCreate(
            ['user_id' => $userId, 'product_id' => $productId, 'variant_id' => $variantId, 'type' => $type, 'notified_at' => null],
            ['country_code' => $countryCode, 'price_at_subscribe' => $price],
        );
    }

    /** @return int alerts fired */
    public function check(): int
    {
        $fired = 0;
        $minDrop = (string) config('gulfcart.alerts.price_drop_min_percent', '5');
        ProductAlert::query()->whereNull('notified_at')->where('created_at', '>', now()->subDays((int) config('gulfcart.alerts.expire_days', 90)))
            ->orderBy('created_at')->chunkById(200, function ($alerts) use (&$fired, $minDrop) {
                foreach ($alerts as $alert) {
                    $variants = $alert->variant_id !== null ? [$alert->variant_id] : $this->catalog->variantIdsOf($alert->product_id);
                    if ($variants === []) {
                        continue;
                    }
                    $price = $this->price($alert->product_id, $alert->variant_id, $alert->country_code);
                    $inStock = collect($this->stock->variantAvailability($variants, $alert->country_code))->contains(fn ($a) => $a !== 'out_of_stock');
                    $due = match ($alert->type) {
                        'back_in_stock' => $inStock && $price !== null,
                        'price_drop' => $inStock && $price !== null && $alert->price_at_subscribe !== null
                            && Decimal::cmp($price, Decimal::mul((string) $alert->price_at_subscribe, Decimal::sub('1', Decimal::div($minDrop, '100', 6), 6), 2)) <= 0,
                        default => false,
                    };
                    if ($due) {
                        $this->fire($alert, (string) $price);
                        $fired++;
                    }
                }
            });

        return $fired;
    }

    private function fire(ProductAlert $alert, string $price): void
    {
        $variant = $this->catalog->variant($alert->variant_id ?? $this->catalog->variantIdsOf($alert->product_id)[0]);
        DB::transaction(function () use ($alert, $price, $variant) {
            $alert->update(['notified_at' => now()]);
            Outbox::record('product_alert', $alert->id, 'engagement.product_alert', [
                'alert_id' => $alert->id, 'user_id' => $alert->user_id, 'type' => $alert->type, 'product_id' => $alert->product_id,
                'product_slug' => $variant?->productSlug, 'product_title' => $variant !== null ? $variant->productTitle : [],
                'price' => $price, 'old_price' => $alert->price_at_subscribe !== null ? (string) $alert->price_at_subscribe : null,
                'currency' => $this->countries->find($alert->country_code)?->currency ?? '', 'country' => $alert->country_code,
            ]);
        });
    }

    private function price(string $productId, ?string $variantId, string $countryCode): ?string
    {
        if ($variantId !== null) {
            return $this->pricing->variantPrices([$variantId], $countryCode)[$variantId]['amount'] ?? null;
        }

        return $this->pricing->fromPrice($productId, $countryCode)['amount'] ?? null;
    }
}
