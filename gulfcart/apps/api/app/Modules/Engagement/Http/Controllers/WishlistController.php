<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Saved products. Returns product IDs; the storefront renders them through GET /products?ids=. */
final class WishlistController
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::table('wishlist_items')->where('user_id', $request->user()->getAuthIdentifier())
            ->orderByDesc('created_at')->limit(200)->pluck('product_id')->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'uuid', 'exists:products,id']]);
        $userId = (string) $request->user()->getAuthIdentifier();
        if (DB::table('wishlist_items')->where('user_id', $userId)->count() >= 200) {
            DB::table('wishlist_items')->where('user_id', $userId)->orderBy('created_at')->limit(1)->delete();
        }
        DB::table('wishlist_items')->insertOrIgnore(['id' => (string) Str::uuid7(), 'user_id' => $userId, 'product_id' => $data['product_id'], 'created_at' => now()]);

        return ApiResponse::success(['product_id' => $data['product_id'], 'saved' => true], 201);
    }

    public function destroy(Request $request, string $productId): Response
    {
        DB::table('wishlist_items')->where('user_id', $request->user()->getAuthIdentifier())->where('product_id', $productId)->delete();

        return response()->noContent();
    }
}
