<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Console\CartRemindersCommand;
use App\Modules\Notifications\Listeners\NotificationsOutboxListener;
use App\Shared\Modules\ModuleServiceProvider;
use App\Shared\Outbox\OutboxMessagePublished;
use Illuminate\Support\Facades\Event;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Notifications';
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadViewsFrom(app_path('Modules/Notifications/Resources/views'), 'notifications');
        Event::listen(OutboxMessagePublished::class, NotificationsOutboxListener::class);
        if ($this->app->runningInConsole()) {
            $this->commands([CartRemindersCommand::class]);
        }
    }
}
