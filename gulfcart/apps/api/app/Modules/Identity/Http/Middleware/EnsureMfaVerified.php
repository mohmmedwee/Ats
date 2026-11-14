<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\MfaSession;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards /admin/*: staff only, MFA enrolled and verified for this session or token. */
final class EnsureMfaVerified
{
    public function __construct(private readonly MfaSession $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isStaff()) {
            throw new ApiException('FORBIDDEN', 403);
        }
        if (! $user->hasMfaEnabled()) {
            throw new ApiException('MFA_SETUP_REQUIRED', 403);
        }
        if (! $this->mfa->isVerified($request, $user)) {
            throw new ApiException('MFA_REQUIRED', 401);
        }

        return $next($request);
    }
}
