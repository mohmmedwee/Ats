<?php

namespace App\Modules\Invoices\Application;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Invoices\Domain\ZatcaQr;
use App\Modules\Invoices\Infrastructure\Models\Invoice;
use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Orders\Domain\OrderData;
use App\Modules\Tax\Contracts\TaxRules;
use App\Shared\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Issues tax invoices (on payment) and credit notes (on refunds). Idempotent: one invoice per
 * order, one credit note per refund. Prices are VAT inclusive; VAT is shown per line and in total.
 * Customs is listed as a non-VAT disbursement line (to confirm with the tax advisor).
 */
final class InvoiceService
{
    public function __construct(private readonly OrderBook $orders, private readonly CountryDirectory $countries, private readonly TaxRules $tax) {}

    public function issueInvoice(string $orderId): ?Invoice
    {
        return DB::transaction(function () use ($orderId): ?Invoice {
            if ($existing = Invoice::query()->where('order_id', $orderId)->where('type', 'invoice')->first()) {
                return $existing;
            }
            $order = $this->orders->get($orderId);
            if ($order === null) {
                return null;
            }

            $lines = [];
            foreach ($order->items as $item) {
                if ($item->isDiscounted()) {
                    // Same per-line VAT the checkout quote computed for the discounted line.
                    $gross = Decimal::sub($item->lineTotal, $item->lineDiscount, 2);
                    $vat = $this->contained($gross, $item->vatRate);
                    $lines[] = [
                        'description' => $item->snapshot['title'] ?? [], 'sku' => $item->snapshot['sku'] ?? null,
                        'quantity' => $item->quantity, 'unit_gross' => $item->unitGross, 'discount' => $item->lineDiscount,
                        'vat_rate' => Decimal::round($item->vatRate, 2), 'net' => Decimal::sub($gross, $vat, 2), 'vat' => $vat, 'gross' => $gross,
                    ];

                    continue;
                }
                $lines[] = [
                    'description' => $item->snapshot['title'] ?? [], 'sku' => $item->snapshot['sku'] ?? null,
                    'quantity' => $item->quantity, 'unit_gross' => $item->unitGross, 'vat_rate' => Decimal::round($item->vatRate, 2),
                    'net' => Decimal::mul($item->unitNet, (string) $item->quantity, 2),
                    'vat' => Decimal::mul($item->unitTax, (string) $item->quantity, 2),
                    'gross' => $item->lineTotal,
                ];
            }
            $itemsVat = Decimal::sum(array_column($lines, 'vat'), 2);
            $shippingVat = Decimal::sub($order->taxTotal, $itemsVat, 2);
            if (Decimal::cmp($order->shippingTotal, '0') > 0) {
                $lines[] = ['description' => ['en' => 'Delivery', 'ar' => 'التوصيل'], 'sku' => null, 'quantity' => 1,
                    'unit_gross' => $order->shippingTotal, 'vat_rate' => Decimal::round($this->tax->shippingVatRate($order->countryCode), 2),
                    'net' => Decimal::sub($order->shippingTotal, $shippingVat, 2), 'vat' => $shippingVat, 'gross' => $order->shippingTotal];
            }
            if (Decimal::cmp($order->customsTotal, '0') > 0) {
                $lines[] = ['description' => ['en' => 'Customs duties (disbursement)', 'ar' => 'رسوم جمركية (مدفوعة نيابة عن العميل)'], 'sku' => null, 'quantity' => 1,
                    'unit_gross' => $order->customsTotal, 'vat_rate' => '0.00', 'net' => $order->customsTotal, 'vat' => '0.00', 'gross' => $order->customsTotal];
            }

            return $this->create('invoice', $order, $lines, null, null);
        });
    }

    /** @param array<string, int> $itemQuantities */
    public function issueCreditNote(string $orderId, string $refundId, string $amount, array $itemQuantities): ?Invoice
    {
        return DB::transaction(function () use ($orderId, $refundId, $amount, $itemQuantities): ?Invoice {
            if ($existing = Invoice::query()->where('refund_id', $refundId)->first()) {
                return $existing;
            }
            $original = Invoice::query()->where('order_id', $orderId)->where('type', 'invoice')->first() ?? $this->issueInvoice($orderId);
            $order = $this->orders->get($orderId);
            if ($order === null || $original === null) {
                return null;
            }

            $lines = [];
            $covered = '0';
            foreach ($itemQuantities as $itemId => $qty) {
                $item = $order->item($itemId);
                if ($item === null) {
                    continue;
                }
                if ($item->isDiscounted()) {
                    $gross = Decimal::mul($item->paidUnitGross(), (string) $qty, 2);
                    $vat = $this->contained($gross, $item->vatRate);
                    $lines[] = ['description' => $item->snapshot['title'] ?? [], 'sku' => $item->snapshot['sku'] ?? null, 'quantity' => $qty,
                        'unit_gross' => $item->paidUnitGross(), 'vat_rate' => Decimal::round($item->vatRate, 2),
                        'net' => Decimal::sub($gross, $vat, 2), 'vat' => $vat, 'gross' => $gross];
                    $covered = Decimal::add($covered, $gross, 2);

                    continue;
                }
                $gross = Decimal::mul($item->unitGross, (string) $qty, 2);
                $lines[] = ['description' => $item->snapshot['title'] ?? [], 'sku' => $item->snapshot['sku'] ?? null, 'quantity' => $qty,
                    'unit_gross' => $item->unitGross, 'vat_rate' => Decimal::round($item->vatRate, 2),
                    'net' => Decimal::mul($item->unitNet, (string) $qty, 2), 'vat' => Decimal::mul($item->unitTax, (string) $qty, 2), 'gross' => $gross];
                $covered = Decimal::add($covered, $gross, 2);
            }
            // Amount not attributable to items (goodwill, shipping, customs): split by the invoice's VAT ratio.
            $rest = Decimal::sub($amount, $covered, 2);
            if (Decimal::cmp($rest, '0') > 0) {
                $ratio = Decimal::cmp((string) $original->gross_total, '0') > 0 ? Decimal::div((string) $original->vat_total, (string) $original->gross_total, 8) : '0';
                $vat = Decimal::round(Decimal::mul($rest, $ratio, 8), 2);
                $lines[] = ['description' => ['en' => 'Refund adjustment', 'ar' => 'تسوية استرداد'], 'sku' => null, 'quantity' => 1,
                    'unit_gross' => $rest, 'vat_rate' => $this->rateOf($rest, $vat), 'net' => Decimal::sub($rest, $vat, 2), 'vat' => $vat, 'gross' => $rest];
            }

            return $this->create('credit_note', $order, $lines, $refundId, $original->id);
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function create(string $type, OrderData $order, array $lines, ?string $refundId, ?string $originalId): Invoice
    {
        $country = $this->countries->find($order->countryCode);
        $settings = $country->settings ?? [];
        $seller = [
            'legal_name' => (string) ($settings['seller']['legal_name'] ?? config('gulfcart.invoices.seller_legal_name')),
            'vat_number' => (string) ($settings['seller']['vat_number'] ?? ''),
            'address' => (string) ($settings['seller']['address'] ?? ''),
        ];
        $net = Decimal::sum(array_column($lines, 'net'), 2);
        $vat = Decimal::sum(array_column($lines, 'vat'), 2);
        $gross = Decimal::add($net, $vat, 2);
        $issuedAt = now();

        return Invoice::query()->create([
            'number' => $this->nextNumber($order->countryCode, $type, (int) $issuedAt->format('Y')),
            'type' => $type, 'order_id' => $order->id, 'refund_id' => $refundId, 'original_invoice_id' => $originalId,
            'country' => $order->countryCode, 'currency' => $order->currency, 'seller' => $seller,
            'buyer' => ['name' => $order->shippingAddress['full_name'] ?? null, 'city' => $order->shippingAddress['city'] ?? null, 'country' => $order->countryCode],
            'lines' => $lines, 'net_total' => $net, 'vat_total' => $vat, 'gross_total' => $gross,
            'qr_payload' => $order->countryCode === 'SA'
                ? ZatcaQr::payload($seller['legal_name'], $seller['vat_number'], $issuedAt->toIso8601String(), $gross, $vat)
                : null,
            'issued_at' => $issuedAt,
        ]);
    }

    private function nextNumber(string $country, string $type, int $year): string
    {
        DB::table('invoice_sequences')->insertOrIgnore(['country' => $country, 'type' => $type, 'year' => $year, 'last_number' => 0]);
        $row = DB::table('invoice_sequences')->where(['country' => $country, 'type' => $type, 'year' => $year])->lockForUpdate()->first();
        $next = (int) $row->last_number + 1;
        DB::table('invoice_sequences')->where(['country' => $country, 'type' => $type, 'year' => $year])->update(['last_number' => $next]);

        return sprintf('%s-%s-%d-%06d', $type === 'invoice' ? 'INV' : 'CN', $country, $year, $next);
    }

    private function rateOf(string $gross, string $vat): string
    {
        $net = Decimal::sub($gross, $vat, 2);

        return Decimal::cmp($net, '0') > 0 ? Decimal::round(Decimal::mul(Decimal::div($vat, $net, 8), '100', 8), 2) : '0';
    }

    private function contained(string $gross, string $ratePercent): string
    {
        return Decimal::cmp($ratePercent, '0') === 0 ? '0.00'
            : Decimal::round(Decimal::div(Decimal::mul($gross, $ratePercent, 8), Decimal::add('100', $ratePercent, 8), 8), 2);
    }
}
