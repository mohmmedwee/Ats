<?php

namespace Tests\Feature\Engagement;

use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Notifications\Infrastructure\Models\NotificationLog;
use App\Modules\Pricing\Application\Repricer;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class ProductAlertTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_back_in_stock_alert_fires_once_when_stock_returns(): void
    {
        ['product' => $product, 'supplierProduct' => $sp] = $this->sellableProduct(['AE'], ['stock' => 'out_of_stock']);
        $h = $this->customerAuth() + ['X-Country' => 'AE'];
        $id = $this->postJson("/api/v1/products/{$product->id}/alerts", ['type' => 'back_in_stock'], $h)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/products/{$product->id}/alerts", ['type' => 'back_in_stock'], $h)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/alerts', $h)->assertOk()->assertJsonCount(1, 'data');

        $this->artisan('alerts:check')->expectsOutputToContain('0 alert(s)')->assertSuccessful();

        $sp->update(['stock_status' => 'in_stock']);
        app(Repricer::class)->reprice($product->id, notify: false); // supplier sync reprices on stock changes
        app(ListingRefresher::class)->refresh($product->id);
        $this->artisan('alerts:check')->expectsOutputToContain('1 alert(s)')->assertSuccessful();
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->assertSame(1, NotificationLog::query()->where('template', 'back_in_stock')->where('dedupe_key', "alert:{$id}")->count());

        $this->artisan('alerts:check')->expectsOutputToContain('0 alert(s)')->assertSuccessful();
        $this->getJson('/api/v1/alerts', $h)->assertJsonCount(0, 'data');
    }

    public function test_price_drop_alert_needs_a_real_drop_and_alerts_can_be_removed(): void
    {
        ['product' => $product, 'supplierProduct' => $sp] = $this->sellableProduct(['AE'], ['cost' => '30.00']);
        $h = $this->customerAuth() + ['X-Country' => 'AE'];
        $id = $this->postJson("/api/v1/products/{$product->id}/alerts", ['type' => 'price_drop'], $h)->assertCreated()->json('data.id');

        // Tiny change (under 5%): nothing.
        $sp->update(['cost_price' => '29.90']);
        app(Repricer::class)->reprice($product->id, notify: false);
        $this->artisan('alerts:check')->expectsOutputToContain('0 alert(s)')->assertSuccessful();

        $sp->update(['cost_price' => '20.00']);
        app(Repricer::class)->reprice($product->id, notify: false);
        app(ListingRefresher::class)->refresh($product->id);
        $this->artisan('alerts:check')->expectsOutputToContain('1 alert(s)')->assertSuccessful();
        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->assertSame(1, NotificationLog::query()->where('template', 'price_drop')->where('dedupe_key', "alert:{$id}")->count());

        $other = $this->postJson("/api/v1/products/{$product->id}/alerts", ['type' => 'back_in_stock'], $h)->json('data.id');
        $this->deleteJson("/api/v1/alerts/{$other}", [], $h)->assertNoContent();
        $this->deleteJson("/api/v1/alerts/{$other}", [], $h)->assertNotFound();
        $this->postJson('/api/v1/products/00000000-0000-0000-0000-000000000000/alerts', ['type' => 'price_drop'], $h)->assertNotFound();
    }
}
