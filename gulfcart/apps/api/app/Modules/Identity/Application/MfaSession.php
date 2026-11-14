<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/**
 * Tracks whether the current authentication (SPA session or API token) has passed MFA.
 *
 * - Session auth: the session stores the verified user ID.
 * - Token auth: staff tokens are issued with the `mfa:pending` ability and
 *   exchanged for a full token after a successful challenge.
 */
final class MfaSession
{
    public const ABILITY_PENDING = 'mfa:pending';

    public const ABILITY_VERIFIED = 'mfa:verified';

    private const SESSION_KEY = 'mfa_verified_user';

    public function isVerified(Request $request, User $user): bool
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $token->can(self::ABILITY_VERIFIED);
        }

        if ($token instanceof TransientToken || $token === null) {
            return $request->hasSession() && $request->session()->get(self::SESSION_KEY) === $user->id;
        }

        return false;
    }

    /**
     * Marks the current authentication as verified. For token auth, returns a
     * replacement full-access token (the pending token is revoked).
     */
    public function markVerified(Request $request, User $user): ?string
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $name = $token->name;
            $token->delete();

            return $user->createToken($name, ['*'])->plainTextToken;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $user->id);
        }

        return null;
    }
}
