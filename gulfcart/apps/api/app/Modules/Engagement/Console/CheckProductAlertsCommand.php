<?php

namespace App\Modules\Engagement\Console;

use App\Modules\Engagement\Application\ProductAlerts;
use Illuminate\Console\Command;

final class CheckProductAlertsCommand extends Command
{
    protected $signature = 'alerts:check';

    protected $description = 'Notify customers whose product is back in stock or cheaper';

    public function handle(ProductAlerts $alerts): int
    {
        $this->info($alerts->check().' alert(s) sent.');

        return self::SUCCESS;
    }
}
