<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Shipping\Http\Controllers\ShippingMethodController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::COUNTRIES_MANAGE])->group(function (): void {
    Route::get('shipping-methods', [ShippingMethodController::class, 'index']);
    Route::post('shipping-methods', [ShippingMethodController::class, 'store']);
    Route::patch('shipping-methods/{shippingMethod}', [ShippingMethodController::class, 'update']);
});
