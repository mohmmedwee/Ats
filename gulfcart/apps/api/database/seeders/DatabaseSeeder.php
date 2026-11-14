<?php

namespace Database\Seeders;

use App\Modules\Countries\Database\Seeders\CountrySeeder;
use App\Modules\Identity\Database\Seeders\DevSuperAdminSeeder;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Pricing\Database\Seeders\PricingSeeder;
use App\Modules\Shipping\Database\Seeders\ShippingMethodSeeder;
use App\Modules\Tax\Database\Seeders\TaxSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            RolesAndPermissionsSeeder::class,
            TaxSeeder::class,
            ShippingMethodSeeder::class,
            PricingSeeder::class,
            DevSuperAdminSeeder::class,
        ]);
    }
}
