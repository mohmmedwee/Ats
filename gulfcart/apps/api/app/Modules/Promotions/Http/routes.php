<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Promotions\Http\Controllers\AdminCouponController;
use App\Modules\Promotions\Http\Controllers\LoyaltyController;
use App\Modules\Promotions\Http\Controllers\NewsletterController;
use App\Modules\Promotions\Http\Controllers\OfferController;
use Illuminate\Support\Facades\Route;

Route::get('products/{productId}/offers', [OfferController::class, 'forProduct'])->whereUuid('productId');
Route::post('newsletter', [NewsletterController::class, 'subscribe'])->middleware('throttle:5,1');
Route::post('newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe'])->middleware('throttle:10,1');

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
    Route::get('newsletter', [NewsletterController::class, 'admin']);
    Route::get('offers', [OfferController::class, 'index']);
    Route::post('offers', [OfferController::class, 'store']);
    Route::patch('offers/{offer}', [OfferController::class, 'update']);
    Route::post('loyalty/adjust', [LoyaltyController::class, 'adjust']);
});
