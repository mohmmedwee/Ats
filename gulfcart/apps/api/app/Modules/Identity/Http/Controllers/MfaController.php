<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Identity\Application\MfaSession;
use App\Modules\Identity\Application\TotpService;
use App\Modules\Identity\Http\Requests\MfaCodeRequest;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff TOTP: setup → confirm (enrol) → challenge (each login). */
final class MfaController
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly MfaSession $mfa,
        private readonly AuditLogger $audit,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        if ($user->hasMfaEnabled()) {
            throw new ApiException('MFA_ALREADY_ENABLED', 409);
        }

        $secret = $this->totp->generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_last_timestamp' => null])->save();

        return ApiResponse::success([
            'secret' => $secret,
            'otpauth_url' => $this->totp->otpauthUrl($user, $secret),
        ]);
    }

    public function confirm(MfaCodeRequest $request): JsonResponse
    {
        $user = $this->staff($request);
        if ($user->hasMfaEnabled()) {
            throw new ApiException('MFA_ALREADY_ENABLED', 409);
        }
        if (! $this->totp->verify($user, (string) $request->input('code'))) {
            throw new ApiException('MFA_CODE_INVALID', 422);
        }

        $user->forceFill(['mfa_confirmed_at' => now()])->save();
        $codes = $this->totp->regenerateRecoveryCodes($user);
        $token = $this->mfa->markVerified($request, $user);
        $this->audit->record('auth.mfa_enabled', 'user', $user->id);

        return ApiResponse::success(array_filter(['recovery_codes' => $codes, 'token' => $token]));
    }

    public function challenge(MfaCodeRequest $request): JsonResponse
    {
        $user = $this->staff($request);
        if (! $user->hasMfaEnabled()) {
            throw new ApiException('MFA_SETUP_REQUIRED', 403);
        }

        $valid = $request->filled('code')
            ? $this->totp->verify($user, (string) $request->input('code'))
            : $this->totp->useRecoveryCode($user, (string) $request->input('recovery_code'));

        if (! $valid) {
            $this->audit->record('auth.mfa_failed', 'user', $user->id);
            throw new ApiException('MFA_CODE_INVALID', 422);
        }

        $token = $this->mfa->markVerified($request, $user);
        $this->audit->record('auth.mfa_verified', 'user', $user->id, null, ['method' => $request->filled('code') ? 'totp' : 'recovery_code']);

        return ApiResponse::success(array_filter(['verified' => true, 'token' => $token]));
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isStaff()) {
            throw new ApiException('FORBIDDEN', 403);
        }

        return $user;
    }
}
