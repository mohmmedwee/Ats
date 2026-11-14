<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Procurement\Http\Controllers\AdminProcurementController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/procurement')->middleware(['auth:sanctum', 'mfa.verified'])->group(function (): void {
    Route::get('/', [AdminProcurementController::class, 'index'])->middleware('permission:'.Permissions::PROCUREMENT_VIEW);
    Route::get('{task}', [AdminProcurementController::class, 'show'])->middleware('permission:'.Permissions::PROCUREMENT_VIEW);
    Route::middleware(['permission:'.Permissions::PROCUREMENT_ACT, 'idempotent'])->group(function (): void {
        Route::post('{task}/retry', [AdminProcurementController::class, 'retry']);
        Route::post('{task}/approve', [AdminProcurementController::class, 'approve']);
        Route::post('{task}/switch-source', [AdminProcurementController::class, 'switchSource']);
        Route::post('{task}/cancel', [AdminProcurementController::class, 'cancel']);
    });
});
