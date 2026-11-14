<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Application\EloquentUserDirectory;
use App\Modules\Identity\Application\LogSmsSender;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Shared\Modules\ModuleServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }

    public function register(): void
    {
        $this->app->singleton(UserDirectory::class, EloquentUserDirectory::class);
        // Replace with a provider adapter once an SMS provider is contracted.
        $this->app->singletonIf(SmsSender::class, LogSmsSender::class);
    }

    public function boot(): void
    {
        parent::boot();

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers()->max(255);

            return $this->app->environment('production') ? $rule->uncompromised() : $rule;
        });

        // The email links to the storefront, which calls the signed API URL (relative signature).
        VerifyEmail::createUrlUsing(static function (object $user): string {
            $signed = URL::temporarySignedRoute('verification.verify', now()->addHours(24), [
                'id' => $user->getKey(), 'hash' => sha1((string) $user->getEmailForVerification()),
            ], absolute: false);

            return rtrim((string) config('gulfcart.frontend_url'), '/').'/verify-email?'.http_build_query(['link' => $signed]);
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
        RateLimiter::for('otp', static fn (Request $r) => [
            Limit::perMinutes(10, 3)->by('otp:'.preg_replace('/\D/', '', (string) $r->input('phone'))),
            Limit::perHour(10)->by('otp-user:'.($r->user()?->getAuthIdentifier() ?? $r->ip())),
        ]);
        RateLimiter::for('otp-verify', static fn (Request $r) => Limit::perMinute(10)->by('otpv:'.($r->user()?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('mfa', static fn (Request $r) => Limit::perMinute(5)->by('mfa:'.($r->user()?->getAuthIdentifier() ?? $r->ip())));
    }
}
