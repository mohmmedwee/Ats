<?php

namespace App\Modules\Invoices\Providers;

use App\Modules\Invoices\Listeners\InvoicesOutboxListener;
use App\Shared\Modules\ModuleServiceProvider;
use App\Shared\Outbox\OutboxMessagePublished;
use Illuminate\Support\Facades\Event;

final class InvoicesServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Invoices';
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadViewsFrom(app_path('Modules/Invoices/Resources/views'), 'invoices');
        Event::listen(OutboxMessagePublished::class, InvoicesOutboxListener::class);
    }
}
