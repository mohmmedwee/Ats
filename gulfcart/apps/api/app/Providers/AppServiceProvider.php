<?php

namespace App\Providers;

use App\Shared\Health\HealthCommand;
use App\Shared\Health\QueueProbeCommand;
use App\Shared\Outbox\DispatchOutboxCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->environment('production'));

        RateLimiter::for('api', static function (Request $request) {
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute(120)->by('user:'.$user->getAuthIdentifier())
                : Limit::perMinute(60)->by('ip:'.$request->ip());
        });

        if ($this->app->runningInConsole()) {
            $this->commands([HealthCommand::class, QueueProbeCommand::class, DispatchOutboxCommand::class]);
        }
    }
}
