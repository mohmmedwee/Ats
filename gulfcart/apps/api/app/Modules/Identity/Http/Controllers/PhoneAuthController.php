<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Countries\Contracts\AddressRules;
use App\Modules\Identity\Application\IssueAuthentication;
use App\Modules\Identity\Application\PhoneLogin;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Mobile number + SMS code sign-in and sign-up for customers. */
final class PhoneAuthController
{
    public function __construct(
        private readonly PhoneLogin $phoneLogin,
        private readonly IssueAuthentication $issue,
        private readonly AddressRules $addresses,
        private readonly AuditLogger $audit,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $this->phoneLogin->start($this->phone($request), app()->getLocale());

        return ApiResponse::success(['sent' => true, 'expires_in_seconds' => 600]);
    }

    public function verify(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);
        [$user, $created] = $this->phoneLogin->verify($phone, $data['code'], $data['name'] ?? null, app()->getLocale());
        $token = ($this->issue)($request, $user, $request->input('device_name'));
        $this->audit->record($created ? 'auth.registered_by_phone' : 'auth.login_by_phone', 'user', $user->id, null, null, $user->id);

        return ApiResponse::success(array_filter([
            'user' => (new UserResource($user))->resolve($request),
            'new_user' => $created,
            'profile_incomplete' => $user->name === '',
            'token' => $token,
        ], static fn ($v) => $v !== null));
    }

    /** Lets a signed-in customer complete or change their name and email. */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'preferred_locale' => ['sometimes', 'in:en,ar'],
        ]);
        if (array_key_exists('email', $data)) {
            $data['email'] = $data['email'] === null ? null : mb_strtolower($data['email']);
            if ($data['email'] !== $user->email) {
                $data['email_verified_at'] = null;
            }
        }
        $user->forceFill($data)->save();
        if (($data['email'] ?? null) !== null && $user->email_verified_at === null) {
            $user->sendEmailVerificationNotification();
        }
        $this->audit->record('auth.profile_updated', 'user', $user->id, null, array_keys($data), $user->id);

        return ApiResponse::success((new UserResource($user->refresh()))->resolve($request));
    }

    /** Accepts a local or international number for AE/SA and returns it in E.164. */
    private function phone(Request $request): string
    {
        $data = $request->validate([
            'country' => ['required', Rule::in((array) config('gulfcart.countries'))],
            'phone' => ['required', 'string', 'max:30'],
        ]);
        $normalized = $this->addresses->normalizePhone($data['country'], (string) preg_replace('/[\s\-().]/', '', (string) $data['phone']));
        $request->merge(['phone_e164' => $normalized]);
        $request->validate(['phone_e164' => ['regex:/^\+(9715|9665)\d{8}$/']], ['phone_e164.regex' => __('validation.regex', ['attribute' => __('validation.attributes.phone')])]);

        return $normalized;
    }
}
