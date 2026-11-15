<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Pricing\Http\Controllers\FlashSaleController;
use App\Modules\Pricing\Http\Controllers\PricingController;
use Illuminate\Support\Facades\Route;

Route::get('flash-sales/current', [FlashSaleController::class, 'current']);

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::PRICING_MANAGE])->group(function (): void {
    Route::get('pricing-rules', [PricingController::class, 'rules']);
    Route::post('pricing-rules', [PricingController::class, 'storeRule']);
    Route::patch('pricing-rules/{pricingRule}', [PricingController::class, 'updateRule']);
    Route::post('pricing/simulate', [PricingController::class, 'simulate']);
    Route::get('exchange-rates', [PricingController::class, 'rates']);
    Route::post('exchange-rates', [PricingController::class, 'storeRate']);
    Route::get('flash-sales', [FlashSaleController::class, 'index']);
    Route::post('flash-sales', [FlashSaleController::class, 'store']);
    Route::patch('flash-sales/{flashSale}', [FlashSaleController::class, 'update']);
});
