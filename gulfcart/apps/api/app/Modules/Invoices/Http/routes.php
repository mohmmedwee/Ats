<?php

use App\Modules\Invoices\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('orders/{id}/invoices', [InvoiceController::class, 'forOrder'])->whereUuid('id');
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereUuid('id');
    Route::get('invoices/{id}/html', [InvoiceController::class, 'html'])->whereUuid('id');
});
