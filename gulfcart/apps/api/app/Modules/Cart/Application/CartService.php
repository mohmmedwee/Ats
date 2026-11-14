<?php

namespace App\Modules\Cart\Application;

use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\Domain\CartData;
use App\Modules\Cart\Domain\CartLine;
use App\Modules\Cart\Infrastructure\Models\Cart;
use App\Modules\Cart\Infrastructure\Models\CartItem;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Contracts\CatalogStock;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Pricing\Contracts\PriceBook;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Shared\Exceptions\ApiException;
use App\Shared\I18n\Translations;
use App\Shared\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carts are bound to one country/currency. Signed-in users have one active cart per
 * country; guests use an opaque token (X-Cart-Token) that is merged into the user's
 * cart at the first authenticated request carrying it.
 */
final class CartService implements CartReader
{
    public const MAX_LINES = 50;

    public const MAX_QTY = 20;

    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly PriceBook $prices,
        private readonly CatalogStock $stock,
        private readonly ShippingQuoter $shipping,
    ) {}

    public function resolve(?string $userId, ?string $guestToken, CountryData $country, bool $create): ?Cart
    {
        $guest = $guestToken !== null
            ? Cart::query()->where('guest_token', $guestToken)->where('status', 'active')->first()
            : null;

        if ($userId === null) {
            if ($guest !== null && $guest->country_id === $country->id) {
                return $guest;
            }

            return $create ? Cart::query()->create([
                'guest_token' => Str::random(48), 'country_id' => $country->id, 'currency' => $country->currency, 'status' => 'active',
            ]) : null;
        }

        return DB::transaction(function () use ($userId, $guest, $country, $create): ?Cart {
            $cart = Cart::query()->where('user_id', $userId)->where('country_id', $country->id)->where('status', 'active')->lockForUpdate()->first();
            if ($cart === null && ($create || $guest !== null)) {
                $cart = Cart::query()->create(['user_id' => $userId, 'country_id' => $country->id, 'currency' => $country->currency, 'status' => 'active']);
            }
            if ($cart !== null && $guest !== null && $guest->country_id === $country->id) {
                $this->merge($guest, $cart);
            }

            return $cart;
        });
    }

    public function add(Cart $cart, string $variantId, int $quantity, CountryData $country): CartItem
    {
        $this->assertPurchasable($variantId, $country);

        return DB::transaction(function () use ($cart, $variantId, $quantity, $country) {
            $item = $cart->items()->where('variant_id', $variantId)->lockForUpdate()->first();
            $newQty = ($item?->quantity ?? 0) + $quantity;
            if ($newQty > self::MAX_QTY) {
                throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['quantity' => ['Maximum '.self::MAX_QTY.' per item.']]]);
            }
            if ($item === null && $cart->items()->count() >= self::MAX_LINES) {
                throw new ApiException('CART_LIMIT_REACHED', 422);
            }
            $price = $this->prices->priceFor($variantId, $country->code)?->gross;

            return $item !== null
                ? tap($item)->update(['quantity' => $newQty])
                : $cart->items()->create(['variant_id' => $variantId, 'quantity' => $quantity, 'price_at_add' => $price]);
        });
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        $item->update(['quantity' => $quantity]);
    }

    /**
     * Current state of the cart with live prices and stock, plus issues to show the customer.
     * When $acknowledge is true, price_at_add is moved to the current price (customer has seen it).
     *
     * @return array<string, mixed>
     */
    public function present(Cart $cart, CountryData $country, bool $acknowledge = false): array
    {
        $items = $cart->items()->get();
        $variantIds = $items->pluck('variant_id')->all();
        $variants = $this->catalog->variants($variantIds);
        $prices = $this->prices->pricesFor($variantIds, $country->code);
        $stock = $this->stock->variantAvailability($variantIds, $country->code);

        $lines = [];
        $issues = [];
        $subtotal = '0';
        foreach ($items as $item) {
            $variant = $variants[$item->variant_id] ?? null;
            $price = $prices[$item->variant_id] ?? null;
            $availability = $stock[$item->variant_id] ?? 'out_of_stock';
            $purchasable = $variant !== null && $variant->isPurchasable() && $price !== null && $availability !== 'out_of_stock';

            if (! $purchasable) {
                $issues[] = ['item_id' => $item->id, 'code' => $price === null || $variant === null || ! $variant->isPurchasable() ? 'UNAVAILABLE' : 'OUT_OF_STOCK'];
            } elseif ($item->price_at_add !== null && Decimal::cmp($item->price_at_add, $price->gross) !== 0) {
                $issues[] = ['item_id' => $item->id, 'code' => 'PRICE_CHANGED', 'old_price' => ['amount' => Decimal::round($item->price_at_add, 2), 'currency' => $price->currency], 'new_price' => ['amount' => $price->gross, 'currency' => $price->currency]];
            }

            $lineTotal = $purchasable ? Decimal::mul($price->gross, (string) $item->quantity, 2) : null;
            if ($lineTotal !== null) {
                $subtotal = Decimal::add($subtotal, $lineTotal, 2);
            }

            $lines[] = [
                'id' => $item->id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
                'product' => $variant ? ['id' => $variant->productId, 'slug' => $variant->productSlug, 'title' => Translations::pick($variant->productTitle), 'image' => $variant->imageUrl] : null,
                'sku' => $variant?->sku,
                'attributes' => (object) ($variant?->attributes ?? []),
                'unit_price' => $price ? ['amount' => $price->gross, 'currency' => $price->currency] : null,
                'line_total' => $lineTotal !== null ? ['amount' => $lineTotal, 'currency' => $country->currency] : null,
                'availability' => $availability,
                'purchasable' => $purchasable,
            ];

            if ($acknowledge && $price !== null && $item->price_at_add !== $price->gross) {
                $item->update(['price_at_add' => $price->gross]);
            }
        }

        return [
            'id' => $cart->id,
            'country' => $country->code,
            'currency' => $cart->currency,
            'items' => $lines,
            'item_count' => array_sum(array_column($lines, 'quantity')),
            'subtotal' => ['amount' => Decimal::round($subtotal, 2), 'currency' => $cart->currency],
            'free_shipping' => $this->freeShipping($subtotal, $country, $cart->currency),
            'prices_include_tax' => true,
            'issues' => $issues,
            'valid' => $issues === [] && $lines !== [],
        ];
    }

    /** @return array{threshold: array{amount: string, currency: string}, remaining: array{amount: string, currency: string}, reached: bool}|null */
    private function freeShipping(string $subtotal, CountryData $country, string $currency): ?array
    {
        $threshold = $this->shipping->freeShippingThreshold($country->code);
        if ($threshold === null) {
            return null;
        }
        $remaining = Decimal::sub($threshold, $subtotal, 2);
        $reached = Decimal::cmp($remaining, '0') <= 0;

        return [
            'threshold' => ['amount' => $threshold, 'currency' => $currency],
            'remaining' => ['amount' => $reached ? '0.00' : $remaining, 'currency' => $currency],
            'reached' => $reached,
        ];
    }

    public function activeCartFor(string $userId, string $countryId): ?CartData
    {
        $cart = Cart::query()->with('items')->where('user_id', $userId)->where('country_id', $countryId)->where('status', 'active')->first();

        return $cart === null ? null : new CartData($cart->id, $cart->user_id, $cart->country_id, $cart->currency,
            $cart->items->map(fn (CartItem $i) => new CartLine($i->id, $i->variant_id, $i->quantity))->all());
    }

    public function markConverted(string $cartId): void
    {
        Cart::query()->whereKey($cartId)->update(['status' => 'converted']);
    }

    private function assertPurchasable(string $variantId, CountryData $country): void
    {
        $variant = $this->catalog->variant($variantId);
        if ($variant === null || ! $variant->isPurchasable() || $this->prices->priceFor($variantId, $country->code) === null) {
            throw new ApiException('PRODUCT_NOT_AVAILABLE_IN_COUNTRY', 422, ['variant_id' => $variantId]);
        }
        if (($this->stock->variantAvailability([$variantId], $country->code)[$variantId] ?? 'out_of_stock') === 'out_of_stock') {
            throw new ApiException('PRODUCT_OUT_OF_STOCK', 422, ['variant_id' => $variantId]);
        }
    }

    private function merge(Cart $guest, Cart $target): void
    {
        foreach ($guest->items()->get() as $item) {
            $existing = $target->items()->where('variant_id', $item->variant_id)->first();
            if ($existing !== null) {
                $existing->update(['quantity' => min(self::MAX_QTY, $existing->quantity + $item->quantity)]);
            } elseif ($target->items()->count() < self::MAX_LINES) {
                $target->items()->create($item->only(['variant_id', 'quantity', 'price_at_add']));
            }
        }
        $guest->update(['status' => 'merged']);
    }
}
