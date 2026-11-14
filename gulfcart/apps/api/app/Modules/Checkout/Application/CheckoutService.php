<?php

namespace App\Modules\Checkout\Application;

use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\Domain\CartData;
use App\Modules\Checkout\Contracts\CheckoutSessions;
use App\Modules\Checkout\Domain\CheckoutSessionData;
use App\Modules\Checkout\Infrastructure\Models\CheckoutSession;
use App\Modules\Checkout\Infrastructure\Models\CustomerAddress;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Countries\Domain\CountryData;
use App\Shared\Exceptions\ApiException;

final class CheckoutService implements CheckoutSessions
{
    public function __construct(
        private readonly CartReader $carts,
        private readonly QuoteBuilder $quotes,
        private readonly CountryDirectory $countries,
    ) {}

    public function cart(string $userId, CountryData $country): CartData
    {
        return $this->carts->activeCartFor($userId, $country->id) ?? throw new ApiException('CART_EMPTY', 422);
    }

    public function address(string $userId, string $addressId): CustomerAddress
    {
        return CustomerAddress::query()->where('user_id', $userId)->whereKey($addressId)->first()
            ?? throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['address_id' => ['Address not found.']]]);
    }

    public function createSession(string $userId, CountryData $country, string $addressId, string $method, ?string $couponCode = null): CheckoutSession
    {
        $cart = $this->cart($userId, $country);
        $address = $this->address($userId, $addressId);
        $quote = $this->quotes->build($cart, $country, $address, $method, liveCheck: true, couponCode: $couponCode, userId: $userId);
        $expires = now()->addMinutes((int) config('gulfcart.pricing.quote_validity_minutes', 30));
        $quote['expires_at'] = $expires->toIso8601String();

        // Only one open session per cart: older ones are cancelled.
        CheckoutSession::query()->where('cart_id', $cart->id)->where('status', 'open')->update(['status' => 'cancelled']);

        return CheckoutSession::query()->create([
            'cart_id' => $cart->id,
            'user_id' => $userId,
            'country_id' => $country->id,
            'address_id' => $address->id,
            'shipping_method_id' => $quote['shipping_method']['id'],
            'currency' => $country->currency,
            'quote' => $quote,
            'subtotal' => $quote['totals']['subtotal'],
            'shipping_total' => $quote['totals']['shipping'],
            'tax_total' => $quote['totals']['tax'],
            'customs_total' => $quote['totals']['customs'],
            'discount_total' => $quote['totals']['discount'],
            'grand_total' => $quote['totals']['total'],
            'status' => 'open',
            'expires_at' => $expires,
        ]);
    }

    public function lockForOrder(string $sessionId, string $userId): ?CheckoutSessionData
    {
        $s = CheckoutSession::query()->whereKey($sessionId)->where('user_id', $userId)->lockForUpdate()->first();
        if ($s === null) {
            return null;
        }
        $code = (string) ($s->quote['country'] ?? '');

        return new CheckoutSessionData($s->id, $s->user_id, $s->cart_id, $s->country_id, $code, $s->currency, $s->address_id, $s->quote, $s->isUsable());
    }

    public function markCompleted(string $sessionId): void
    {
        CheckoutSession::query()->whereKey($sessionId)->update(['status' => 'completed']);
    }

    /** @return array<string, mixed> */
    public function present(CheckoutSession $s): array
    {
        return [
            'id' => $s->id,
            'status' => $s->isUsable() ? 'open' : ($s->status === 'open' ? 'expired' : $s->status),
            'address_id' => $s->address_id,
            'quote' => QuoteBuilder::publicView($s->quote),
            'quote_expires_at' => $s->expires_at->toIso8601String(),
        ];
    }
}
