<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Orders\Http\Controllers\AdminOrderController;
use App\Modules\Orders\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('orders/track', [OrderController::class, 'track'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store'])->middleware('idempotent');
    Route::get('orders/{id}', [OrderController::class, 'show'])->whereUuid('id');
    Route::get('orders/{id}/tracking', [OrderController::class, 'tracking'])->whereUuid('id');
    Route::post('orders/{id}/cancel', [OrderController::class, 'cancel'])->whereUuid('id');
});

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::ORDERS_VIEW_ANY])->group(function (): void {
    Route::get('orders', [AdminOrderController::class, 'index']);
    Route::get('orders/{order}', [AdminOrderController::class, 'show']);
});
