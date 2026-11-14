<?php

namespace App\Modules\Identity\Providers;

use App\Shared\Modules\ModuleServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }

    public function boot(): void
    {
        parent::boot();

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers()->max(255);

            return $this->app->environment('production') ? $rule->uncompromised() : $rule;
        });

        ResetPassword::createUrlUsing(static function (object $user, string $token): string {
            $email = method_exists($user, 'getEmailForPasswordReset') ? $user->getEmailForPasswordReset() : '';

            return rtrim((string) config('gulfcart.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $email]);
        });

        RateLimiter::for('login', static fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.mb_strtolower((string) $r->input('login')).'|'.$r->ip()),
            Limit::perMinute(20)->by('login-ip:'.$r->ip()),
        ]);
        RateLimiter::for('register', static fn (Request $r) => Limit::perMinute(5)->by('register:'.$r->ip()));
        RateLimiter::for('password', static fn (Request $r) => Limit::perMinute(3)->by('password:'.$r->ip()));
        RateLimiter::for('mfa', static fn (Request $r) => Limit::perMinute(5)->by('mfa:'.($r->user()?->getAuthIdentifier() ?? $r->ip())));
    }
}
