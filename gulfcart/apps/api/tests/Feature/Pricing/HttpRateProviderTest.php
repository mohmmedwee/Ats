<?php

namespace Tests\Feature\Pricing;

use App\Modules\Pricing\Application\ConfiguredRateProvider;
use App\Modules\Pricing\Application\EloquentExchangeRates;
use App\Modules\Pricing\Application\HttpRateProvider;
use App\Modules\Pricing\Infrastructure\Models\ExchangeRate;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HttpRateProviderTest extends TestCase
{
    public function test_live_rates_are_stored_and_outliers_rejected(): void
    {
        config(['gulfcart.fx.http.bases' => ['USD', 'EUR'], 'gulfcart.fx.http.url' => 'https://fx.test/latest/{base}']);
        Http::fake([
            'fx.test/latest/USD' => Http::response(['result' => 'success', 'rates' => ['AED' => 3.6725, 'SAR' => 3.75, 'USD' => 1]]),
            // Seeded EUR/AED is 4.00; 9.99 is a >10% jump → rejected.
            'fx.test/latest/EUR' => Http::response(['rates' => ['AED' => 9.99, 'SAR' => 4.10]]),
        ]);
        $before = ExchangeRate::query()->count();
        $this->travel(1)->seconds();

        $stored = app(EloquentExchangeRates::class)->refresh(new HttpRateProvider);

        $this->assertSame(3, $stored);
        $this->assertSame($before + 3, ExchangeRate::query()->count());
        $this->assertSame('http:fx.test', ExchangeRate::query()->latest('created_at')->value('source'));
        $this->assertSame('4.00000000', (string) ExchangeRate::query()->where('base_currency', 'EUR')->where('target_currency', 'AED')->orderByDesc('effective_at')->value('rate'));
    }

    public function test_feed_failures_store_nothing(): void
    {
        config(['gulfcart.fx.http.bases' => ['USD'], 'gulfcart.fx.http.url' => 'https://fx.test/latest/{base}']);
        Http::fake(['fx.test/*' => Http::response('down', 503)]);

        $this->assertSame(0, app(EloquentExchangeRates::class)->refresh(new HttpRateProvider));
    }

    public function test_same_second_refreshes_do_not_collide(): void
    {
        $rates = app(EloquentExchangeRates::class);
        $provider = new ConfiguredRateProvider;
        $this->freezeSecond();
        $rates->refresh($provider);

        $this->assertSame(0, $rates->refresh($provider));
    }

    public function test_outbox_worker_dispatches_and_exits(): void
    {
        DB::transaction(fn () => Outbox::record('x', (string) Str::uuid7(), 'test.event', []));
        OutboxEvent::query()->update(['status' => 'pending']);

        $this->artisan('outbox:work --max-time=1 --sleep=0.1')->assertSuccessful();

        $this->assertSame('dispatched', OutboxEvent::query()->value('status'));
    }
}
