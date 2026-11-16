<?php

use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use App\Modules\Identity\Domain\Permissions;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified'])->group(function (): void {
    Route::get('dashboard', [AnalyticsController::class, 'dashboard'])->middleware('permission:'.Permissions::ORDERS_VIEW_ANY);
    Route::get('finance/profit', [AnalyticsController::class, 'profit'])->middleware('permission:'.Permissions::PRICING_MANAGE.'|'.Permissions::AUDIT_VIEW);
    Route::get('finance/reports', [AnalyticsController::class, 'finance'])->middleware('permission:'.Permissions::PRICING_MANAGE.'|'.Permissions::AUDIT_VIEW);
});
