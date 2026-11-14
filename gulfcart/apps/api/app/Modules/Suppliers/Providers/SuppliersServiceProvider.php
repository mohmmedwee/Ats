<?php

namespace App\Modules\Suppliers\Providers;

use App\Modules\Catalog\Contracts\CatalogStock;
use App\Modules\Catalog\Contracts\SellabilityRule;
use App\Modules\Suppliers\Application\DefaultConnectorResolver;
use App\Modules\Suppliers\Application\EloquentSourcingDirectory;
use App\Modules\Suppliers\Application\HasAuthorizedSource;
use App\Modules\Suppliers\Console\SyncSuppliersCommand;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Contracts\SourcingDirectory;
use App\Shared\Modules\ModuleServiceProvider;

final class SuppliersServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Suppliers';
    }

    public function register(): void
    {
        $this->app->singleton(EloquentSourcingDirectory::class);
        $this->app->alias(EloquentSourcingDirectory::class, SourcingDirectory::class);
        $this->app->alias(EloquentSourcingDirectory::class, CatalogStock::class);
        $this->app->singleton(ConnectorResolver::class, DefaultConnectorResolver::class);
        $this->app->tag([HasAuthorizedSource::class], SellabilityRule::TAG);
    }

    public function boot(): void
    {
        parent::boot();
        if ($this->app->runningInConsole()) {
            $this->commands([SyncSuppliersCommand::class]);
        }
    }
}
