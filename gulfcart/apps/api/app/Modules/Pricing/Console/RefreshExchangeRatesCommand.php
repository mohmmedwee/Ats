<?php

namespace App\Modules\Pricing\Console;

use App\Modules\Pricing\Application\EloquentExchangeRates;
use App\Modules\Pricing\Contracts\ExchangeRateProvider;
use Illuminate\Console\Command;

final class RefreshExchangeRatesCommand extends Command
{
    protected $signature = 'fx:refresh {--reprice : Reprice all products afterwards}';

    protected $description = 'Store a fresh exchange-rate snapshot from the configured provider';

    public function handle(EloquentExchangeRates $rates, ExchangeRateProvider $provider): int
    {
        $count = $rates->refresh($provider);
        $this->info("Stored {$count} rate(s) from {$provider->name()}.");

        if ($this->option('reprice')) {
            $this->call('pricing:reprice');
        }

        return self::SUCCESS;
    }
}
