<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Identity\Domain\Permissions;
use Illuminate\Support\Facades\Route;

Route::get('admin/audit-logs', [AuditLogController::class, 'index'])
    ->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::AUDIT_VIEW]);
