<?php

namespace App\Modules\Cart\Http\Controllers;

use App\Modules\Cart\Application\CartService;
use App\Modules\Cart\Infrastructure\Models\Cart;
use App\Modules\Cart\Infrastructure\Models\CartItem;
use App\Modules\Countries\Domain\CountryData;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Works for guests (X-Cart-Token header) and signed-in customers (session or token). */
final class CartController
{
    public function __construct(private readonly CartService $carts) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->cart($request, false);

        return $cart === null
            ? ApiResponse::success(['id' => null, 'country' => $this->country($request)->code, 'currency' => $this->country($request)->currency, 'items' => [], 'item_count' => 0, 'issues' => [], 'valid' => false])
            : $this->respond($request, $cart);
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'between:1,'.CartService::MAX_QTY],
        ]);
        $cart = $this->cart($request, true);
        $this->carts->add($cart, $data['variant_id'], (int) $data['quantity'], $this->country($request));

        return $this->respond($request, $cart, 201);
    }

    public function update(Request $request, string $item): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'between:1,'.CartService::MAX_QTY]]);
        [$cart, $line] = $this->line($request, $item);
        $this->carts->updateQuantity($line, (int) $data['quantity']);

        return $this->respond($request, $cart);
    }

    public function destroy(Request $request, string $item): JsonResponse
    {
        [$cart, $line] = $this->line($request, $item);
        $line->delete();

        return $this->respond($request, $cart);
    }

    public function validateCart(Request $request): JsonResponse
    {
        $cart = $this->cart($request, false) ?? throw new ApiException('CART_EMPTY', 422);

        return ApiResponse::success($this->carts->present($cart, $this->country($request), acknowledge: true));
    }

    private function respond(Request $request, Cart $cart, int $status = 200): JsonResponse
    {
        $payload = $this->carts->present($cart, $this->country($request));
        if ($cart->user_id === null) {
            $payload['guest_token'] = $cart->guest_token;
        }

        return ApiResponse::success($payload, $status);
    }

    private function cart(Request $request, bool $create): ?Cart
    {
        $token = $request->headers->get('X-Cart-Token');

        return $this->carts->resolve($this->userId($request), is_string($token) && $token !== '' ? $token : null, $this->country($request), $create);
    }

    /** @return array{0: Cart, 1: CartItem} */
    private function line(Request $request, string $itemId): array
    {
        $cart = $this->cart($request, false) ?? throw new ApiException('NOT_FOUND', 404);
        $line = $cart->items()->whereKey($itemId)->first() ?? throw new ApiException('NOT_FOUND', 404);

        return [$cart, $line];
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user('sanctum')?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    private function country(Request $request): CountryData
    {
        /** @var CountryData */
        return $request->attributes->get('country');
    }
}
