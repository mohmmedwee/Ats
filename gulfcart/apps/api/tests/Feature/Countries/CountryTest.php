<?php

namespace Tests\Feature\Countries;

use App\Modules\Countries\Contracts\CountryDirectory;
use Tests\TestCase;

final class CountryTest extends TestCase
{
    public function test_launch_countries_are_seeded_with_their_currencies(): void
    {
        $this->getJson('/api/v1/countries')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'AE')
            ->assertJsonPath('data.0.currency', 'AED')
            ->assertJsonPath('data.0.timezone', 'Asia/Dubai')
            ->assertJsonPath('data.0.supported_locales', ['ar-AE', 'en-AE'])
            ->assertJsonPath('data.1.code', 'SA')
            ->assertJsonPath('data.1.currency', 'SAR');
    }

    public function test_country_defaults_to_ae(): void
    {
        $this->getJson('/api/v1/countries/current')
            ->assertOk()->assertJsonPath('data.code', 'AE')->assertHeader('X-Country', 'AE');
    }

    public function test_country_header_selects_saudi_arabia_with_arabic_name(): void
    {
        $this->getJson('/api/v1/countries/current', ['X-Country' => 'sa', 'Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.code', 'SA')
            ->assertJsonPath('data.name', 'المملكة العربية السعودية');
    }

    public function test_unsupported_country_is_rejected(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/countries/current', ['X-Country' => 'QA']), 422, 'COUNTRY_NOT_SUPPORTED')
            ->assertJsonPath('error.details.country', 'QA');
    }

    public function test_country_lookup_survives_a_serializing_cache(): void
    {
        // Regression: cached objects came back from Redis as __PHP_Incomplete_Class.
        $this->getJson('/api/v1/countries/current', ['X-Country' => 'AE'])->assertOk();
        $this->app->forgetInstance(CountryDirectory::class);

        $this->getJson('/api/v1/countries/current', ['X-Country' => 'SA'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'SAR');
    }
}
