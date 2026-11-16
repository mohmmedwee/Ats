<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Shared\Http\ApiResponse;
use App\Shared\Money\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'country' => ['nullable', Rule::in((array) config('gulfcart.countries'))],
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

    /**
     * Profit view for a period and one country: daily series and products ranked by estimated profit.
     * Same basis as the finance report: revenue without VAT and refunds, minus landed cost (locked FX),
     * minus estimated payment fees; customs is a pass-through.
     */
    public function profit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'country' => ['required', Rule::in((array) config('gulfcart.countries'))],
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $fee = (float) config('gulfcart.pricing.payment_fee_percent', '0') / 100;
        $currency = (string) DB::table('countries')->where('code', $data['country'])->value('currency_code');

        // Per line: share kept after refunds, revenue ex VAT after discount, cost of the kept units.
        $lines = DB::table('analytics_item_facts')->where('country', $data['country'])->whereBetween('paid_at', [$from, $to])->get();
        $calc = function ($l) use ($fee): array {
            $kept = $l->quantity > 0 ? max(0, $l->quantity - $l->refunded_quantity) / $l->quantity : 0;
            $gross = ((float) $l->line_total - (float) $l->line_discount) * $kept;
            $net = $gross / (1 + (float) $l->vat_rate / 100);
            $cost = (float) $l->unit_landed_cost * max(0, $l->quantity - $l->refunded_quantity);
            $fees = $gross * $fee;

            return ['units' => max(0, $l->quantity - $l->refunded_quantity), 'revenue' => $net, 'cost' => $cost, 'fees' => $fees, 'profit' => $net - $cost - $fees, 'discount' => (float) $l->line_discount * $kept];
        };
        $r2 = fn (float $v) => number_format($v, 2, '.', '');

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'orders' => [], 'revenue' => 0.0, 'cost' => 0.0, 'fees' => 0.0, 'profit' => 0.0];
        }
        $products = [];
        $totals = ['revenue' => 0.0, 'cost' => 0.0, 'fees' => 0.0, 'profit' => 0.0, 'discount' => 0.0, 'units' => 0];
        $orders = [];
        foreach ($lines as $l) {
            $c = $calc($l);
            $day = Carbon::parse($l->paid_at)->toDateString();
            if (isset($days[$day])) {
                $days[$day]['orders'][$l->order_id] = true;
                foreach (['revenue', 'cost', 'fees', 'profit'] as $k) {
                    $days[$day][$k] += $c[$k];
                }
            }
            $orders[$l->order_id] = true;
            foreach (['revenue', 'cost', 'fees', 'profit', 'discount', 'units'] as $k) {
                $totals[$k] += $c[$k];
            }
            $key = $l->product_id ?? 'deleted';
            $products[$key] ??= ['product_id' => $l->product_id, 'title' => ['en' => $l->title_en, 'ar' => $l->title_ar], 'image' => $l->image_url, 'units' => 0, 'revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0];
            foreach (['units', 'revenue', 'cost', 'profit'] as $k) {
                $products[$key][$k] += $c[$k];
            }
        }
        $fmtProduct = fn ($p) => ['product_id' => $p['product_id'], 'title' => $p['title'], 'image' => $p['image'], 'units' => $p['units'],
            'revenue' => $r2($p['revenue']), 'cost' => $r2($p['cost']), 'profit' => $r2($p['profit']),
            'margin_percent' => $p['revenue'] > 0 ? $r2($p['profit'] / $p['revenue'] * 100) : null];
        $ranked = collect($products)->sortByDesc('profit')->values();

        return ApiResponse::success([
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'country' => $data['country'], 'currency' => $currency,
            'totals' => [
                'orders' => count($orders), 'units' => $totals['units'], 'revenue_ex_vat' => $r2($totals['revenue']), 'landed_cost' => $r2($totals['cost']),
                'payment_fees' => $r2($totals['fees']), 'profit' => $r2($totals['profit']), 'discounts' => $r2($totals['discount']),
                'margin_percent' => $totals['revenue'] > 0 ? $r2($totals['profit'] / $totals['revenue'] * 100) : null,
                'average_order_profit' => count($orders) > 0 ? $r2($totals['profit'] / count($orders)) : null,
            ],
            'daily' => array_values(array_map(fn ($d) => ['date' => $d['date'], 'orders' => count($d['orders']), 'revenue_ex_vat' => $r2($d['revenue']), 'profit' => $r2($d['profit'])], $days)),
            'top_products' => $ranked->take(10)->map($fmtProduct)->values()->all(),
            'losing_products' => $ranked->filter(fn ($p) => $p['profit'] < 0)->sortBy('profit')->take(10)->map($fmtProduct)->values()->all(),
        ]);
    }
}
