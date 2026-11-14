<?php

namespace Tests\Feature\Shopping;

use App\Modules\Pricing\Infrastructure\Models\ProductPrice;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class CartTest extends TestCase
{
    use CatalogFixtures;

    public function test_guest_builds_a_cart_with_a_token(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);

        $res = $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 2])->assertCreated()
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.currency', 'AED')
            ->assertJsonPath('data.valid', true);
        $token = $res->json('data.guest_token');
        $this->assertNotEmpty($token);

        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 1], ['X-Cart-Token' => $token])
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonCount(1, 'data.items');

        $unit = (float) $res->json('data.items.0.unit_price.amount');
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])->assertOk()
            ->assertJsonPath('data.subtotal.amount', number_format($unit * 3, 2, '.', ''));
    }

    public function test_quantity_limit_and_unavailable_variants_are_rejected(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        ['variant' => $saOnly] = $this->sellableProduct(['SA']);

        $this->assertErrorCode($this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 21]), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->postJson('/api/v1/cart/items', ['variant_id' => $saOnly->id, 'quantity' => 1]), 422, 'PRODUCT_NOT_AVAILABLE_IN_COUNTRY');
    }

    public function test_guest_cart_merges_into_the_customer_cart_at_login(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $token = $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 2])->json('data.guest_token');
        $customer = $this->customer();
        $auth = ['Authorization' => 'Bearer '.$customer->createToken('t')->plainTextToken];

        $this->getJson('/api/v1/cart', $auth + ['X-Cart-Token' => $token])->assertOk()
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonMissingPath('data.guest_token');
        $this->assertDatabaseHas('carts', ['guest_token' => $token, 'status' => 'merged']);
    }

    public function test_carts_are_per_country(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE', 'SA']);
        $auth = ['Authorization' => 'Bearer '.$this->customer()->createToken('t')->plainTextToken];

        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 1], $auth)->assertJsonPath('data.currency', 'AED');
        $this->getJson('/api/v1/cart', $auth + ['X-Country' => 'SA'])->assertJsonPath('data.item_count', 0)->assertJsonPath('data.currency', 'SAR');
    }

    public function test_validation_reports_price_changes_and_stock_then_acknowledges(): void
    {
        ['variant' => $v, 'supplierProduct' => $sp] = $this->sellableProduct(['AE']);
        $auth = ['Authorization' => 'Bearer '.$this->customer()->createToken('t')->plainTextToken];
        $itemId = $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 1], $auth)->json('data.items.0.id');

        ProductPrice::query()->where('variant_id', $v->id)->update(['gross_price' => '999.00']);

        $this->postJson('/api/v1/cart/validate', [], $auth)->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.issues.0.code', 'PRICE_CHANGED')
            ->assertJsonPath('data.issues.0.new_price.amount', '999.00');
        $this->postJson('/api/v1/cart/validate', [], $auth)->assertJsonPath('data.valid', true);

        $sp->mappings()->update(['stock_status' => 'out_of_stock']);
        $this->getJson('/api/v1/cart', $auth)->assertJsonPath('data.issues.0.code', 'OUT_OF_STOCK')->assertJsonPath('data.valid', false);

        $this->deleteJson("/api/v1/cart/items/{$itemId}", [], $auth)->assertOk()->assertJsonCount(0, 'data.items');
    }
}
