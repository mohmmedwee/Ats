<?php

namespace Tests\Feature\Invoices;

use App\Modules\Invoices\Domain\ZatcaQr;
use App\Modules\Invoices\Infrastructure\Models\Invoice;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\QueryException;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class InvoiceTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_paid_order_gets_one_invoice_with_vat_matching_the_order(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 2]], $auth);
        $this->payOrder($order, $auth);

        $invoice = Invoice::query()->where('order_id', $order->id)->sole();
        $order->refresh();
        $this->assertMatchesRegularExpression('/^INV-AE-\d{4}-000001$/', $invoice->number);
        $this->assertSame((string) $order->grand_total, (string) $invoice->gross_total);
        $this->assertSame((string) $order->tax_total, (string) $invoice->vat_total);
        $this->assertNull($invoice->qr_payload); // UAE: no ZATCA QR

        $this->getJson("/api/v1/orders/{$order->id}/invoices", $auth)->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/v1/invoices/{$invoice->id}/html", $auth)->assertOk()->assertSee('Simplified Tax Invoice')->assertSee($invoice->number)
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'");
        $this->assertSame(['5.00'], array_values(array_unique(array_column($invoice->lines, 'vat_rate'))));
    }

    public function test_saudi_invoices_carry_a_zatca_qr_payload_and_numbering_is_per_country(): void
    {
        $this->patchJson('/api/v1/admin/countries/SA', ['settings' => ['seller' => ['legal_name' => 'GulfCart KSA', 'vat_number' => '310122393500003']]], $this->asStaff(['admin']))->assertOk();
        $this->resetAuth();
        ['variant' => $v] = $this->sellableProduct(['SA']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth, 'SA');
        $this->payOrder($order, $auth);

        $invoice = Invoice::query()->where('order_id', $order->id)->sole();
        $this->assertStringStartsWith('INV-SA-', $invoice->number);
        $fields = ZatcaQr::decode((string) $invoice->qr_payload);
        $this->assertSame('GulfCart KSA', $fields[1]);
        $this->assertSame('310122393500003', $fields[2]);
        $this->assertSame((string) $invoice->gross_total, $fields[4]);
        $this->assertSame((string) $invoice->vat_total, $fields[5]);

        // The printable invoice shows a scannable QR image of the same payload.
        $html = $this->get("/api/v1/invoices/{$invoice->id}/html", $auth)->assertOk()->getContent();
        $this->assertStringContainsString('data-test="zatca-qr"', (string) $html);
        $this->assertStringContainsString('<svg', (string) $html);
    }

    public function test_refunds_produce_credit_notes_and_invoices_are_immutable(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->payAndRefund($v, $auth);

        $credit = Invoice::query()->where('order_id', $order->id)->where('type', 'credit_note')->sole();
        $this->assertStringStartsWith('CN-AE-', $credit->number);
        $this->assertSame('20.00', (string) $credit->gross_total);
        $this->assertSame((float) $credit->gross_total, (float) $credit->net_total + (float) $credit->vat_total);

        $this->expectException(QueryException::class);
        $credit->update(['gross_total' => '1.00']);
    }

    public function test_other_customers_cannot_see_invoices(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $this->payOrder($order, $auth);
        $id = Invoice::query()->value('id');

        $this->resetAuth();
        $this->assertErrorCode($this->getJson("/api/v1/invoices/{$id}", $this->customerAuth()), 404, 'NOT_FOUND');
    }

    private function payAndRefund($variant, array $auth): Order
    {
        $order = $this->placeOrder([[$variant, 1]], $auth);
        $this->payOrder($order, $auth);
        $this->postJson('/api/v1/admin/refunds', ['order_id' => $order->id, 'amount' => '20.00', 'reason_code' => 'goodwill'], $this->asStaff(['finance']) + ['Idempotency-Key' => 'refund-cn-0000000001'])->assertCreated();

        return $order;
    }
}
