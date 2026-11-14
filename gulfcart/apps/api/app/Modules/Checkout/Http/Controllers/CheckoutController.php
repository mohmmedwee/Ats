<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Application\CheckoutService;
use App\Modules\Checkout\Application\QuoteBuilder;
use App\Modules\Checkout\Infrastructure\Models\CheckoutSession;
use App\Modules\Countries\Domain\CountryData;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutController
{
    public function __construct(private readonly CheckoutService $checkout, private readonly QuoteBuilder $quotes) {}

    public function shippingOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['address_id' => ['required', 'uuid']]);
        $country = $this->country($request);
        $address = $this->checkout->address($this->userId($request), $data['address_id']);
        if ($address->country_id !== $country->id) {
            throw new ApiException('ADDRESS_COUNTRY_MISMATCH', 422);
        }
        $options = $this->quotes->shippingOptions($this->checkout->cart($this->userId($request), $country), $country);

        return ApiResponse::success(array_map(fn ($o) => [
            'code' => $o->code, 'name' => Translations::pick($o->name),
            'price' => ['amount' => $o->amount, 'currency' => $o->currency], 'free' => $o->free,
        ], $options));
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $country = $this->country($request);
        $quote = $this->quotes->build(
            $this->checkout->cart($this->userId($request), $country), $country,
            $this->checkout->address($this->userId($request), $data['address_id']), $data['shipping_method'], liveCheck: false,
            couponCode: $data['coupon_code'] ?? null, userId: $this->userId($request),
        );

        return ApiResponse::success(QuoteBuilder::publicView($quote));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $session = $this->checkout->createSession($this->userId($request), $this->country($request), $data['address_id'], $data['shipping_method'], $data['coupon_code'] ?? null);

        return ApiResponse::success($this->checkout->present($session), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $session = CheckoutSession::query()->where('user_id', $this->userId($request))->whereKey($id)->first()
            ?? throw new ApiException('NOT_FOUND', 404);

        return ApiResponse::success($this->checkout->present($session));
    }

    /** @return array{address_id: string, shipping_method: string} */
    private function validated(Request $request): array
    {
        /** @var array{address_id: string, shipping_method: string, coupon_code?: string|null} */
        return $request->validate([
            'address_id' => ['required', 'uuid'],
            'shipping_method' => ['required', 'string', 'max:50'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }

    private function country(Request $request): CountryData
    {
        /** @var CountryData */
        return $request->attributes->get('country');
    }
}
