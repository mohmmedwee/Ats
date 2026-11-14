<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Identity\Application\OtpService;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VerificationController
{
    public function __construct(private readonly OtpService $otp, private readonly AuditLogger $audit) {}

    public function sendOtp(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $this->otp->send($this->user($request), $phone);

        return ApiResponse::success(['sent' => true, 'expires_in_seconds' => 600]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $code = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']])['code'];
        $user = $this->user($request);
        $this->otp->verify($user, $phone, $code);
        $this->audit->record('auth.phone_verified', 'user', $user->id);

        return ApiResponse::success((new UserResource($user->refresh()))->resolve($request));
    }

    /** Signed link from the verification email (validated by the `signed:relative` middleware). */
    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::query()->find($id);
        if ($user === null || $user->email === null || ! hash_equals(sha1($user->email), $hash)) {
            throw new ApiException('INVALID_SIGNATURE', 403);
        }
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->audit->record('auth.email_verified', 'user', $user->id, null, null, $user->id);
        }

        return ApiResponse::success(['verified' => true]);
    }

    public function resendEmail(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->email !== null && $user->email_verified_at === null) {
            $user->sendEmailVerificationNotification();
        }

        return ApiResponse::success(['sent' => $user->email_verified_at === null]);
    }

    private function phone(Request $request): string
    {
        $raw = preg_replace('/[\s\-().]/', '', (string) $request->input('phone')) ?? '';
        $request->merge(['phone' => $raw]);

        return $request->validate(['phone' => ['required', 'regex:/^\+(971|966)5\d{8}$/']])['phone'];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
