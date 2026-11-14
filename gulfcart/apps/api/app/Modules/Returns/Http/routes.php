<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Returns\Http\Controllers\AdminReturnController;
use App\Modules\Returns\Http\Controllers\ReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('orders/{id}/return', [ReturnController::class, 'store'])->whereUuid('id');
    Route::get('returns', [ReturnController::class, 'index']);
    Route::get('returns/{id}', [ReturnController::class, 'show'])->whereUuid('id');
});

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified'])->group(function (): void {
    Route::get('returns', [AdminReturnController::class, 'index'])->middleware('permission:'.Permissions::ORDERS_VIEW_ANY);
    Route::post('returns/{return}/{action}', [AdminReturnController::class, 'action'])
        ->whereIn('action', ['approve', 'reject', 'receive', 'refund'])
        ->middleware(['permission:'.Permissions::REFUNDS_REQUEST, 'idempotent']);
});
