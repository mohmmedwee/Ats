<?php

namespace Tests\Feature\Hardening;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class HardeningTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/v1/countries')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_cors_allows_only_the_storefront_origin_with_credentials(): void
    {
        $this->call('OPTIONS', '/api/v1/products', [], [], [], [
            'HTTP_ORIGIN' => 'http://spa.gulfcart.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://spa.gulfcart.test')->assertHeader('Access-Control-Allow-Credentials', 'true');

        $evil = $this->call('OPTIONS', '/api/v1/products', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_address_pii_is_encrypted_at_rest(): void
    {
        $auth = $this->customerAuth();
        $this->postJson('/api/v1/addresses', ['country' => 'AE', 'full_name' => 'Sara Ahmed', 'phone' => '0501234567', 'address_line_1' => 'Villa 12', 'city' => 'Dubai', 'state' => 'DXB'], $auth)
            ->assertCreated()->assertJsonPath('data.full_name', 'Sara Ahmed');

        $raw = DB::table('customer_addresses')->first();
        $this->assertStringNotContainsString('Sara', (string) $raw->full_name);
        $this->assertStringNotContainsString('971', (string) $raw->phone);
        $this->assertStringNotContainsString('Villa', (string) $raw->address_line_1);
    }

    public function test_dashboard_and_finance_report(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 2]], $auth);
        $this->payOrder($order, $auth);

        $this->getJson('/api/v1/admin/dashboard', $this->asStaff(['operations']))->assertOk()
            ->assertJsonPath('data.paid_today.0.orders', 1)
            ->assertJsonPath('data.paid_today.0.revenue', $order->refresh()->grand_total)
            ->assertJsonPath('data.sellable_products.AE', 1)
            ->assertJsonPath('data.exchange_rates_stale', false);

        $report = $this->getJson('/api/v1/admin/finance/reports?from='.now()->toDateString().'&to='.now()->toDateString(), $this->asStaff(['finance']))
            ->assertOk()->json('data.by_country.0');
        $this->assertSame('AE', $report['country']);
        $this->assertSame(1, $report['orders']);
        $this->assertSame($order->grand_total, $report['gross_collected']);
        $this->assertGreaterThan(0, (float) $report['landed_cost']);
        $this->assertGreaterThan(0, (float) $report['estimated_contribution']);

        $this->assertErrorCode($this->getJson('/api/v1/admin/finance/reports?from=2026-01-01&to=2026-01-02', $this->asStaff(['support'])), 403, 'FORBIDDEN');
    }

    public function test_audit_trail_is_browsable_by_auditors_only(): void
    {
        $this->customer();
        $this->postJson('/api/v1/auth/login', ['login' => 'nobody@example.com', 'password' => 'whatever-1']);

        $this->getJson('/api/v1/admin/audit-logs?action=auth.', $this->asStaff(['finance']))->assertOk()
            ->assertJsonPath('data.0.action', 'auth.login_failed');
        $this->assertErrorCode($this->getJson('/api/v1/admin/audit-logs', $this->asStaff(['operations'])), 403, 'FORBIDDEN');
    }

    public function test_country_settings_are_editable_and_audited(): void
    {
        $this->patchJson('/api/v1/admin/countries/SA', ['settings' => ['returns' => ['window_days' => 10], 'seller' => ['vat_number' => '300000000000003']]], $this->asStaff(['admin']))
            ->assertOk()->assertJsonPath('data.settings.returns.window_days', 10)->assertJsonPath('data.settings.phone_prefix', '+966');
        $this->assertDatabaseHas('audit_logs', ['action' => 'country.updated']);
        // Launch countries are active; the prepared GCC countries are listed but switched off.
        $rows = collect($this->getJson('/api/v1/admin/countries', $this->asStaff(['admin']))->assertOk()->json('data'));
        $this->assertSame(['AE', 'SA'], $rows->where('is_active', true)->pluck('code')->sort()->values()->all());
        $this->assertSame(['BH', 'KW', 'OM', 'QA'], $rows->where('is_active', false)->pluck('code')->sort()->values()->all());
    }

    public function test_audit_log_cannot_be_edited_or_deleted(): void
    {
        $this->patchJson('/api/v1/admin/countries/AE', ['settings' => ['support' => ['email' => 'help@example.com']]], $this->asStaff(['admin']));
        $id = DB::table('audit_logs')->value('id');
        $this->assertNotNull($id);
        foreach ([
            fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']),
            fn () => DB::table('audit_logs')->where('id', $id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The audit log accepted a change.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'tampered']);
    }
}
