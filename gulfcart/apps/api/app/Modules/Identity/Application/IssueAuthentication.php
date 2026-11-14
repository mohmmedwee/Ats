<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs a user in for the current client:
 * - Stateful SPA requests (Sanctum stateful domains) get a session.
 * - Other clients get a personal access token. Staff tokens start with the
 *   `mfa:pending` ability until MFA is completed.
 */
final class IssueAuthentication
{
    /** @return string|null plain-text token for non-session clients */
    public function __invoke(Request $request, User $user, ?string $deviceName): ?string
    {
        $user->forceFill(['last_login_at' => now()])->save();

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->forget('mfa_verified_user');

            return null;
        }

        $abilities = $user->isStaff() ? [MfaSession::ABILITY_PENDING] : ['*'];

        return $user->createToken($deviceName ?: 'api', $abilities, now()->addDays($user->isStaff() ? 1 : 30))->plainTextToken;
    }
}
