<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Tax\Http\Controllers\TaxRuleController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::COUNTRIES_MANAGE])->group(function (): void {
    Route::get('tax-rules', [TaxRuleController::class, 'taxIndex']);
    Route::post('tax-rules', [TaxRuleController::class, 'taxStore']);
    Route::patch('tax-rules/{taxRule}', [TaxRuleController::class, 'taxUpdate']);
    Route::get('customs-rules', [TaxRuleController::class, 'customsIndex']);
    Route::post('customs-rules', [TaxRuleController::class, 'customsStore']);
    Route::patch('customs-rules/{customsRule}', [TaxRuleController::class, 'customsUpdate']);
});
