<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Identity\Http\Controllers\Admin\UserController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MfaController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('mfa/setup', [MfaController::class, 'setup']);
        Route::post('mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:mfa');
        Route::post('mfa/challenge', [MfaController::class, 'challenge'])->middleware('throttle:mfa');
    });
});

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified'])->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->middleware('permission:'.Permissions::USERS_MANAGE);
    Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('permission:'.Permissions::ROLES_MANAGE);
});
