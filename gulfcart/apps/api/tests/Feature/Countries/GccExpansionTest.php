<?php

namespace Tests\Feature\Countries;

use App\Modules\Countries\Database\Seeders\CountrySeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class GccExpansionTest extends TestCase
{
    use OrderFlow;

    public function test_prepared_countries_are_off_until_enabled_then_get_tax_shipping_and_addresses(): void
    {
        $this->seed(CountrySeeder::class);
        $active = DB::table('countries')->whereIn('code', ['QA', 'KW', 'BH', 'OM'])->pluck('is_active', 'code')->map(fn ($v) => (bool) $v)->all();
        $this->assertCount(4, $active);
        $this->assertNotContains(true, $active);
        $this->assertNotContains('QA', collect($this->getJson('/api/v1/countries')->json('data'))->pluck('code'));

        config(['gulfcart.countries' => ['AE', 'SA', 'QA']]);
        $this->artisan('countries:enable', ['code' => 'qa'])->assertSuccessful();
        $qa = collect($this->getJson('/api/v1/countries')->json('data'))->firstWhere('code', 'QA');
        $this->assertNotNull($qa);
        $this->assertSame(2, DB::table('shipping_methods')->join('countries', 'countries.id', '=', 'shipping_methods.country_id')->where('countries.code', 'QA')->count());
        $this->assertSame(0.0, (float) DB::table('tax_rules')->join('countries', 'countries.id', '=', 'tax_rules.country_id')->where('countries.code', 'QA')->where('tax_type', 'vat')->value('rate_percent'));

        $h = $this->customerAuth() + ['X-Country' => 'QA'];
        $base = ['country' => 'QA', 'full_name' => 'Noor', 'address_line_1' => 'Building 5', 'city' => 'Doha', 'state' => 'DOH'];
        $this->postJson('/api/v1/addresses', $base + ['phone' => '0501234567'], $h)->assertUnprocessable();
        $this->postJson('/api/v1/addresses', $base + ['phone' => '5512 3456'], $h)->assertCreated()->assertJsonPath('data.phone', '+97455123456');

        $this->artisan('countries:enable', ['code' => 'XX'])->assertFailed();
    }
}
