<?php

namespace App\Shared\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * Base provider for a module under app/Modules/<Name>.
 * Loads the module's migrations; routes are registered centrally in routes/api.php.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract protected function moduleName(): string;

    public function boot(): void
    {
        $migrations = app_path('Modules/'.$this->moduleName().'/Database/Migrations');
        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }
    }
}
