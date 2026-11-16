<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Payments\Http\Controllers\AdminCashOnDeliveryController;
use App\Modules\Payments\Http\Controllers\AdminRefundController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('payments/intent', [PaymentController::class, 'intent'])->middleware('idempotent');
    Route::get('payments/methods', [PaymentController::class, 'methods']);
    Route::get('payments/{id}', [PaymentController::class, 'show'])->whereUuid('id');
    Route::post('payments/{id}/confirm', [PaymentController::class, 'confirm'])->whereUuid('id')->middleware('throttle:20,1');
    Route::post('payments/{id}/cod-confirm', [PaymentController::class, 'confirmCashOnDelivery'])->whereUuid('id')->middleware('throttle:10,1');
    Route::post('payments/{id}/cod-resend', [PaymentController::class, 'resendCashOnDeliveryCode'])->whereUuid('id')->middleware('throttle:3,1');
    Route::post('payments/{id}/sandbox-complete', [PaymentController::class, 'sandboxComplete'])->whereUuid('id');
});

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified'])->group(function (): void {
    Route::post('orders/{orderId}/cod/collected', [AdminCashOnDeliveryController::class, 'collected'])->whereUuid('orderId')->middleware('permission:'.Permissions::REFUNDS_REQUEST);
    Route::post('orders/{orderId}/cod/refused', [AdminCashOnDeliveryController::class, 'refused'])->whereUuid('orderId')->middleware('permission:'.Permissions::REFUNDS_REQUEST);
    Route::get('refunds', [AdminRefundController::class, 'index'])->middleware('permission:'.Permissions::REFUNDS_REQUEST.'|'.Permissions::REFUNDS_APPROVE);
    Route::post('refunds', [AdminRefundController::class, 'store'])->middleware(['permission:'.Permissions::REFUNDS_REQUEST, 'idempotent']);
    Route::post('refunds/{refund}/approve', [AdminRefundController::class, 'approve'])->middleware('permission:'.Permissions::REFUNDS_APPROVE);
    Route::post('refunds/{refund}/reject', [AdminRefundController::class, 'reject'])->middleware('permission:'.Permissions::REFUNDS_APPROVE);
});
