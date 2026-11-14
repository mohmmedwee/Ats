<?php

use App\Modules\Checkout\Http\Controllers\AddressController;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('addresses', [AddressController::class, 'index']);
    Route::post('addresses', [AddressController::class, 'store']);
    Route::patch('addresses/{id}', [AddressController::class, 'update'])->whereUuid('id');
    Route::delete('addresses/{id}', [AddressController::class, 'destroy'])->whereUuid('id');

    Route::post('checkout/shipping-options', [CheckoutController::class, 'shippingOptions']);
    Route::post('checkout/quote', [CheckoutController::class, 'quote']);
    Route::post('checkout', [CheckoutController::class, 'store']);
    Route::get('checkout/{id}', [CheckoutController::class, 'show'])->whereUuid('id');
});
