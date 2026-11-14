<?php

namespace App\Modules\Invoices\Http\Controllers;

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Invoices\Infrastructure\Models\Invoice;
use App\Modules\Orders\Contracts\OrderBook;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class InvoiceController
{
    public function __construct(private readonly OrderBook $orders) {}

    public function forOrder(Request $request, string $id): JsonResponse
    {
        $this->authorizeOrder($request, $id);

        return ApiResponse::success(Invoice::query()->where('order_id', $id)->orderBy('issued_at')->get()->map(fn ($i) => $this->present($i))->all());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->find($request, $id)));
    }

    /** Printable bilingual HTML (the browser prints to PDF). */
    public function html(Request $request, string $id): Response
    {
        $invoice = $this->find($request, $id);
        $locale = app()->getLocale();

        return response()->view('invoices::invoice', [
            'invoice' => $invoice, 'locale' => $locale, 'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'title' => fn ($d) => is_array($d) ? Translations::pick($d) : (string) $d,
        ])->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'");
    }

    private function find(Request $request, string $id): Invoice
    {
        $invoice = Invoice::query()->find($id) ?? throw new ApiException('NOT_FOUND', 404);
        $this->authorizeOrder($request, $invoice->order_id);

        return $invoice;
    }

    private function authorizeOrder(Request $request, string $orderId): void
    {
        $user = $request->user();
        $order = $this->orders->get($orderId);
        if ($order === null || ($order->userId !== $user->getAuthIdentifier() && ! $user->can(Permissions::ORDERS_VIEW_ANY))) {
            throw new ApiException('NOT_FOUND', 404);
        }
    }

    /** @return array<string, mixed> */
    private function present(Invoice $i): array
    {
        return $i->only(['id', 'number', 'type', 'order_id', 'refund_id', 'original_invoice_id', 'country', 'currency', 'seller', 'buyer', 'lines', 'net_total', 'vat_total', 'gross_total', 'qr_payload'])
            + ['issued_at' => $i->issued_at->toIso8601String()];
    }
}
