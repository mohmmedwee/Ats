<?php

use App\Modules\Engagement\Http\Controllers\ProductAlertController;
use App\Modules\Engagement\Http\Controllers\ReviewController;
use App\Modules\Engagement\Http\Controllers\WishlistController;
use App\Modules\Identity\Domain\Permissions;
use Illuminate\Support\Facades\Route;

Route::get('products/{productId}/reviews', [ReviewController::class, 'index'])->whereUuid('productId');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('products/{productId}/reviews', [ReviewController::class, 'store'])->whereUuid('productId')->middleware('throttle:10,1');
    Route::get('alerts', [ProductAlertController::class, 'index']);
    Route::post('products/{productId}/alerts', [ProductAlertController::class, 'store'])->whereUuid('productId')->middleware('throttle:30,1');
    Route::delete('alerts/{id}', [ProductAlertController::class, 'destroy'])->whereUuid('id');
    Route::get('wishlist', [WishlistController::class, 'index']);
    Route::post('wishlist', [WishlistController::class, 'store']);
    Route::delete('wishlist/{productId}', [WishlistController::class, 'destroy'])->whereUuid('productId');
});

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::CATALOG_MANAGE])->group(function (): void {
    Route::get('reviews', [ReviewController::class, 'adminIndex']);
    Route::post('reviews/{review}/{action}', [ReviewController::class, 'moderate'])->whereIn('action', ['approve', 'reject']);
});
