<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Promotions\Http\Controllers\AdminCouponController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::PRICING_MANAGE])->group(function (): void {
    Route::get('coupons', [AdminCouponController::class, 'index']);
    Route::post('coupons', [AdminCouponController::class, 'store']);
    Route::patch('coupons/{coupon}', [AdminCouponController::class, 'update']);
});
