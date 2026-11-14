<?php

namespace Tests\Feature\Shopping;

use App\Modules\Checkout\Infrastructure\Models\CheckoutSession;
use App\Modules\Identity\Infrastructure\Models\User;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class CheckoutTest extends TestCase
{
    use CatalogFixtures;

    private User $user;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->customer();
        $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    }

    private function aeAddress(): string
    {
        return $this->postJson('/api/v1/addresses', [
            'country' => 'AE', 'full_name' => 'Sara Ahmed', 'phone' => '050 123 4567', 'address_line_1' => 'Villa 12, Street 5',
            'city' => 'Dubai', 'state' => 'DXB',
        ], $this->auth)->assertCreated()->assertJsonPath('data.phone', '+971501234567')->assertJsonPath('data.is_default', true)->json('data.id');
    }

    public function test_address_validation_is_country_specific(): void
    {
        $this->assertErrorCode($this->postJson('/api/v1/addresses', [
            'country' => 'SA', 'full_name' => 'A', 'phone' => '+971501234567', 'address_line_1' => 'x', 'city' => 'Riyadh', 'state' => 'DXB', 'postal_code' => '123',
        ], $this->auth), 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['fields' => ['phone', 'state', 'postal_code']]]]);

        $this->postJson('/api/v1/addresses', [
            'country' => 'SA', 'full_name' => 'A', 'phone' => '0551234567', 'address_line_1' => 'x', 'city' => 'Riyadh',
            'state' => 'RIY', 'postal_code' => '12345', 'national_address_code' => 'RRRD2929',
        ], $this->auth)->assertCreated()->assertJsonPath('data.phone', '+966551234567');

        $this->getJson('/api/v1/countries/SA/address-format', ['Accept-Language' => 'ar'])->assertOk()->assertJsonPath('data.regions.0.name', 'الرياض');
    }

    public function test_addresses_are_private(): void
    {
        $id = $this->aeAddress();
        $other = ['Authorization' => 'Bearer '.$this->customer()->createToken('t')->plainTextToken];
        $this->resetAuth();

        $this->assertErrorCode($this->patchJson("/api/v1/addresses/{$id}", ['city' => 'X'], $other), 404, 'NOT_FOUND');
    }

    public function test_full_checkout_quote_and_locked_session(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $addressId = $this->aeAddress();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 2], $this->auth)->assertCreated();

        $options = $this->postJson('/api/v1/checkout/shipping-options', ['address_id' => $addressId], $this->auth)->assertOk()->json('data');
        $this->assertSame(['standard', 'express'], array_column($options, 'code'));

        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $addressId, 'shipping_method' => 'express'], $this->auth)
            ->assertOk()->json('data');

        $this->assertSame('AED', $quote['currency']);
        $this->assertSame('35.00', $quote['shipping']);
        $this->assertSame('0.00', $quote['customs']); // under de minimis
        $this->assertSame(
            number_format((float) $quote['subtotal'] + 35 + (float) $quote['customs'] - (float) $quote['discount'], 2, '.', ''),
            $quote['total'],
        );
        // VAT is contained in the total (5/105), not added on top.
        $this->assertEqualsWithDelta((float) $quote['total'] * 5 / 105, (float) $quote['tax'], 0.05);
        $this->assertCount(1, $quote['shipments']);
        $this->assertSame(['min_days' => 5, 'max_days' => 9], $quote['shipments'][0]['estimate']); // 7–12 minus express 2–3

        $session = $this->postJson('/api/v1/checkout', ['address_id' => $addressId, 'shipping_method' => 'express'], $this->auth)
            ->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
        $this->assertSame($quote['total'], $session['quote']['total']);

        $this->getJson("/api/v1/checkout/{$session['id']}", $this->auth)->assertOk();
        $this->travel(31)->minutes();
        $this->getJson("/api/v1/checkout/{$session['id']}", $this->auth)->assertJsonPath('data.status', 'expired');

        $this->assertSame([], $this->leaks(json_encode($session)));
    }

    public function test_items_from_two_suppliers_become_two_shipments_and_customs_apply_above_threshold(): void
    {
        ['variant' => $a] = $this->sellableProduct(['AE'], ['cost' => '400.00']);
        ['variant' => $b] = $this->sellableProduct(['AE'], ['cost' => '5.00']);
        $addressId = $this->aeAddress();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $a->id, 'quantity' => 1], $this->auth);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $b->id, 'quantity' => 1], $this->auth);

        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $addressId, 'shipping_method' => 'standard'], $this->auth)->json('data');

        $this->assertCount(2, $quote['shipments']);
        $this->assertSame('0.00', $quote['shipping']); // free above 200 AED
        // (400 + 12) USD × 3.6725 = 1513.07 AED > 1000 → 5% duty = 75.65
        $this->assertSame('75.65', $quote['customs']);
    }

    public function test_live_stock_check_blocks_checkout_when_supplier_is_out_of_stock(): void
    {
        ['variant' => $v, 'supplier' => $s, 'supplierProduct' => $sp] = $this->sellableProduct(['AE']);
        $addressId = $this->aeAddress();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 1], $this->auth);
        $s->update(['metadata' => $s->metadata + ['mock' => ['out_of_stock' => [$sp->mappings()->value('external_variant_id')]]]]);

        $this->assertErrorCode($this->postJson('/api/v1/checkout', ['address_id' => $addressId, 'shipping_method' => 'standard'], $this->auth), 422, 'PRODUCT_OUT_OF_STOCK');
        $this->assertDatabaseCount('checkout_sessions', 0);
    }

    public function test_address_must_match_the_shopping_country(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE', 'SA']);
        $addressId = $this->aeAddress();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 1], $this->auth + ['X-Country' => 'SA']);

        $this->assertErrorCode($this->postJson('/api/v1/checkout/quote', ['address_id' => $addressId, 'shipping_method' => 'standard'], $this->auth + ['X-Country' => 'SA']), 422, 'ADDRESS_COUNTRY_MISMATCH');
    }

    public function test_checkout_requires_login_and_a_cart(): void
    {
        $this->resetAuth();
        $this->assertErrorCode($this->postJson('/api/v1/checkout', []), 401, 'UNAUTHENTICATED');
        $this->assertErrorCode($this->postJson('/api/v1/checkout', ['address_id' => $this->aeAddress(), 'shipping_method' => 'standard'], $this->auth), 422, 'CART_EMPTY');
    }

    public function test_session_totals_satisfy_the_database_invariant(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $addressId = $this->aeAddress();
        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 3], $this->auth);
        $this->postJson('/api/v1/checkout', ['address_id' => $addressId, 'shipping_method' => 'standard'], $this->auth)->assertCreated();

        $s = CheckoutSession::query()->sole();
        $this->assertSame((float) $s->grand_total, (float) $s->subtotal + (float) $s->shipping_total + (float) $s->customs_total - (float) $s->discount_total);
        $this->assertArrayHasKey('unit_cost', $s->quote['lines'][0]); // internal quote keeps costs for orders
    }

    /** @return list<string> */
    private function leaks(string $json): array
    {
        return array_values(array_filter(['supplier_id', 'unit_cost', 'exchange_rate', 'external_variant_id', 'supplier_product_id', 'variable_cost'], fn ($k) => str_contains($json, '"'.$k.'"')));
    }
}
