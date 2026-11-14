<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Identity\Application\IssueAuthentication;
use App\Modules\Identity\Domain\Permissions;
use App\Modules\Identity\Domain\UserType;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController
{
    public function __construct(
        private readonly IssueAuthentication $issue,
        private readonly AuditLogger $audit,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        /** @var array{name: string, email?: string|null, phone?: string|null, password: string, locale?: string|null, device_name?: string|null} $data */
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'type' => UserType::Customer,
                'status' => 'active',
                'preferred_locale' => $data['locale'] ?? app()->getLocale(),
            ]);
            $user->assignRole(Permissions::ROLE_CUSTOMER);

            return $user;
        });

        $token = ($this->issue)($request, $user, $data['device_name'] ?? null);
        $this->audit->record('auth.registered', 'user', $user->id, null, null, $user->id);

        $payload = (new UserResource($user))->resolve($request);

        return ApiResponse::success($token === null ? $payload : $payload + ['token' => $token], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $login = trim((string) $request->string('login'));
        $field = str_contains($login, '@') ? 'email' : 'phone';
        $value = $field === 'email' ? mb_strtolower($login) : $login;

        $user = User::query()->where($field, $value)->first();

        if ($user === null || $user->password === null || ! Hash::check((string) $request->string('password'), $user->password)) {
            $this->audit->record('auth.login_failed', 'user', $user?->id, null, ['field' => $field], $user?->id);
            throw new ApiException('INVALID_CREDENTIALS', 401);
        }
        if (! $user->isActive()) {
            throw new ApiException('ACCOUNT_DISABLED', 403);
        }

        $token = ($this->issue)($request, $user, $request->input('device_name'));
        $this->audit->record('auth.login', 'user', $user->id, null, null, $user->id);

        return ApiResponse::success(array_filter([
            'user' => (new UserResource($user))->resolve($request),
            'mfa_required' => $user->isStaff() && $user->hasMfaEnabled(),
            'mfa_setup_required' => $user->isStaff() && ! $user->hasMfaEnabled(),
            'token' => $token,
        ], static fn ($v) => $v !== null));
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        $this->audit->record('auth.logout', 'user', $user->id, null, null, $user->id);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success((new UserResource($request->user()))->resolve($request));
    }
}
