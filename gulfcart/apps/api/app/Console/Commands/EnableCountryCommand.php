<?php

namespace App\Console\Commands;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Shipping\Database\Seeders\ShippingMethodSeeder;
use App\Modules\Tax\Database\Seeders\TaxSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Opens a prepared country (KW, QA, BH, OM): switches it on and seeds its starting tax and shipping
 * rules (edit them in admin afterwards). The country must also be listed in SUPPORTED_COUNTRIES.
 * Lives outside the modules because it orchestrates several of them.
 */
final class EnableCountryCommand extends Command
{
    protected $signature = 'countries:enable {code : Two-letter country code, e.g. QA}';

    protected $description = 'Switch on a prepared country and seed its tax and shipping defaults';

    public function handle(CountryDirectory $countries): int
    {
        $code = strtoupper((string) $this->argument('code'));
        if (DB::table('countries')->where('code', $code)->update(['is_active' => true, 'updated_at' => now()]) === 0) {
            $this->error("Unknown country {$code}. Run php artisan db:seed --class=\"App\\Modules\\Countries\\Database\\Seeders\\CountrySeeder\" first.");

            return self::FAILURE;
        }
        $countries->forget();
        $this->callSilently('db:seed', ['--class' => TaxSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => ShippingMethodSeeder::class, '--force' => true]);
        $this->callSilently('fx:refresh');

        $this->info("{$code} is on, with starting VAT, customs and shipping rules.");
        if (! in_array($code, (array) config('gulfcart.countries'), true)) {
            $this->warn("Add {$code} to SUPPORTED_COUNTRIES in the environment (e.g. SUPPORTED_COUNTRIES=AE,SA,{$code}) and restart.");
        }
        $this->line('Next: confirm VAT/customs with a tax advisor (Admin → Tax), set shipping prices (Admin → Shipping), make products available there, then reprice: php artisan pricing:reprice');

        return self::SUCCESS;
    }
}
