<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Promotions\Http\Controllers\AdminCouponController;
use App\Modules\Promotions\Http\Controllers\LoyaltyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('loyalty', [LoyaltyController::class, 'show']);
    Route::post('loyalty/redeem', [LoyaltyController::class, 'redeem'])->middleware('throttle:10,1');
    Route::post('loyalty/referral', [LoyaltyController::class, 'referral'])->middleware('throttle:10,1');
});

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::PRICING_MANAGE])->group(function (): void {
    Route::get('coupons', [AdminCouponController::class, 'index']);
    Route::post('coupons', [AdminCouponController::class, 'store']);
    Route::patch('coupons/{coupon}', [AdminCouponController::class, 'update']);
    Route::get('loyalty', [LoyaltyController::class, 'summary']);
    Route::post('loyalty/adjust', [LoyaltyController::class, 'adjust']);
});
