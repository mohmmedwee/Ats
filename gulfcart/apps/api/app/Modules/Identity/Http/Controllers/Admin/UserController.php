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
