<?php

namespace App\Modules\Pricing\Console;

use App\Modules\Pricing\Application\Repricer;
use App\Modules\Pricing\Application\RuleResolver;
use App\Modules\Suppliers\Contracts\SourcingDirectory;
use Illuminate\Console\Command;

final class RepriceAllCommand extends Command
{
    protected $signature = 'pricing:reprice {--product= : A single product ID}';

    protected $description = 'Recompute stored prices for all products that have supplier sources';

    public function handle(Repricer $repricer, RuleResolver $rules, SourcingDirectory $sourcing): int
    {
        $rules->flush();
        $ids = $this->option('product')
            ? [(string) $this->option('product')]
            : $sourcing->productIdsWithSources();

        foreach ($ids as $id) {
            $repricer->reprice($id);
        }
        $this->info('Repriced '.count($ids).' product(s).');

        return self::SUCCESS;
    }
}
