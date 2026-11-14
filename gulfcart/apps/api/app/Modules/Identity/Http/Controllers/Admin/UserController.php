<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Identity\Domain\Permissions;
use App\Modules\Identity\Domain\UserType;
use App\Modules\Identity\Http\Requests\UpdateUserRolesRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UserController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, $request->integer('per_page', 25)));
        $query = User::query()->with('roles')->orderByDesc('created_at');

        if (in_array($request->query('type'), ['customer', 'staff'], true)) {
            $query->where('type', $request->query('type'));
        }
        if (is_string($q = $request->query('q')) && $q !== '') {
            $query->where(fn ($w) => $w->where('email', 'ILIKE', '%'.$q.'%')->orWhere('name', 'ILIKE', '%'.$q.'%')->orWhere('phone', 'ILIKE', '%'.$q.'%'));
        }

        $page = $query->paginate($perPage);

        return ApiResponse::success(
            UserResource::collection($page->getCollection())->resolve($request),
            200,
            ['pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ]],
        );
    }

    /**
     * Invite a staff member: the account gets a random password and the person sets their own
     * through the password-reset email. Staff must enrol in MFA at first sign-in.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(Permissions::staffRoles())],
        ]);
        if (in_array('super_admin', $data['roles'], true) && ! $request->user()?->hasRole('super_admin')) {
            throw ValidationException::withMessages(['roles' => [__('errors.forbidden')]]);
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => Str::password(32),
                'type' => UserType::Staff, 'status' => 'active', 'preferred_locale' => app()->getLocale(),
            ]);
            $user->syncRoles($data['roles']);

            return $user;
        });
        Password::broker()->sendResetLink(['email' => $user->email]);
        $this->audit->record('user.staff_invited', 'user', $user->id, null, ['email' => $user->email, 'roles' => $data['roles']]);

        return ApiResponse::success((new UserResource($user->refresh()))->resolve($request), 201);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,suspended']]);
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['status' => [__('errors.forbidden')]]);
        }
        $old = $user->status;
        $user->update(['status' => $data['status']]);
        if ($data['status'] === 'suspended') {
            $user->tokens()->delete();
        }
        $this->audit->record('user.status_updated', 'user', $user->id, ['status' => $old], $data);

        return ApiResponse::success((new UserResource($user->refresh()))->resolve($request));
    }

    public function updateRoles(UpdateUserRolesRequest $request, User $user): JsonResponse
    {
        /** @var list<string> $roles */
        $roles = $request->validated('roles');
        $staffRoles = array_values(array_intersect($roles, Permissions::staffRoles()));

        if ($user->type === UserType::Customer && $staffRoles !== []) {
            throw ValidationException::withMessages(['roles' => [__('errors.staff_role_for_customer')]]);
        }
        if ($user->is($request->user()) && ! in_array('super_admin', $roles, true) && $user->hasRole('super_admin')) {
            throw ValidationException::withMessages(['roles' => [__('errors.cannot_remove_own_super_admin')]]);
        }

        $old = $user->getRoleNames()->values()->all();
        DB::transaction(fn () => $user->syncRoles($roles));
        $this->audit->record('user.roles_updated', 'user', $user->id, ['roles' => $old], ['roles' => $roles]);

        return ApiResponse::success((new UserResource($user->refresh()))->resolve($request));
    }
}
