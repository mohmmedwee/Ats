<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Shared\Http\ApiResponse;
use App\Shared\Money\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Admin KPIs and finance reports (read-only, reporting views). */
final class AnalyticsController
{
    public function dashboard(): JsonResponse
    {
        $today = now()->startOfDay();
        $paidToday = DB::table('analytics_order_facts')->whereNotNull('paid_at')->where('paid_at', '>=', $today)
            ->selectRaw('currency, count(*) as orders, sum(paid_total) as revenue')->groupBy('currency')->get();

        return ApiResponse::success([
            'paid_today' => $paidToday->map(fn ($r) => ['currency' => $r->currency, 'orders' => (int) $r->orders, 'revenue' => Decimal::round((string) $r->revenue, 2)])->all(),
            'orders_by_status' => DB::table('orders')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'procurement' => DB::table('procurement_tasks')->whereIn('status', ['exception', 'manual', 'reconciling', 'waiting_retry'])
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'refunds_awaiting_approval' => DB::table('refunds')->where('status', 'requested')->count(),
            'returns_open' => DB::table('returns')->whereIn('status', ['requested', 'approved', 'received'])->count(),
            'sellable_products' => DB::table('product_country_availability as a')->join('countries as c', 'c.id', '=', 'a.country_id')
                ->where('a.is_sellable', true)->selectRaw('c.code, count(*) as n')->groupBy('c.code')->pluck('n', 'code'),
            'exchange_rates_stale' => ! DB::table('exchange_rates')->where('effective_at', '>=', now()->subHours((int) config('gulfcart.fx.max_age_hours', 24)))->exists(),
            'outbox_failed' => DB::table('outbox_events')->where('status', 'failed')->count(),
        ]);
    }

    public function finance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'country' => ['nullable', 'in:AE,SA'],
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $feePercent = (string) config('gulfcart.pricing.payment_fee_percent', '0');

        $rows = DB::table('analytics_order_facts')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->when($data['country'] ?? null, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('country, currency, count(*) as orders, sum(units) as units, sum(paid_total) as gross, sum(tax_total) as vat,
                sum(customs_total) as customs, sum(shipping_total) as shipping, sum(refunded_total) as refunds, sum(landed_cost) as landed_cost')
            ->groupBy('country', 'currency')->orderBy('country')->get();

        return ApiResponse::success([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'by_country' => $rows->map(function ($r) use ($feePercent) {
                $gross = (string) $r->gross;
                $refunds = (string) $r->refunds;
                // Refunds are VAT-inclusive; estimate their VAT share proportionally.
                $vatShare = Decimal::cmp($gross, '0') > 0 ? Decimal::div((string) $r->vat, $gross, 8) : '0';
                $netRevenue = Decimal::sub(Decimal::sub(Decimal::sub($gross, $refunds, 2), (string) $r->vat, 2), Decimal::mul(Decimal::mul($refunds, $vatShare, 8), '-1', 2), 2);
                $fees = Decimal::round(Decimal::div(Decimal::mul($gross, $feePercent, 8), '100', 8), 2);
                $contribution = Decimal::sub(Decimal::sub(Decimal::sub($netRevenue, (string) $r->customs, 2), (string) $r->landed_cost, 2), $fees, 2);

                return [
                    'country' => $r->country, 'currency' => $r->currency, 'orders' => (int) $r->orders, 'units' => (int) $r->units,
                    'gross_collected' => Decimal::round($gross, 2), 'refunds' => Decimal::round($refunds, 2),
                    'vat_collected' => Decimal::round((string) $r->vat, 2), 'customs_collected' => Decimal::round((string) $r->customs, 2),
                    'shipping_collected' => Decimal::round((string) $r->shipping, 2), 'net_revenue_ex_vat' => $netRevenue,
                    'landed_cost' => Decimal::round((string) $r->landed_cost, 2), 'estimated_payment_fees' => $fees,
                    'estimated_contribution' => $contribution,
                    'contribution_margin_percent' => Decimal::cmp($netRevenue, '0') > 0 ? Decimal::round(Decimal::mul(Decimal::div($contribution, $netRevenue, 8), '100', 8), 2) : null,
                ];
            })->all(),
            'notes' => 'Estimates: landed cost uses the exchange rate locked at sale; fees use the configured rate; customs is treated as a pass-through.',
        ]);
    }
}
