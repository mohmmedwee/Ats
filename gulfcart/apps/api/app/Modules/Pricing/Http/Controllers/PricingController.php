<?php

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Pricing\Domain\PricingEngine;
use App\Modules\Pricing\Domain\PricingException;
use App\Modules\Pricing\Domain\PricingInput;
use App\Modules\Pricing\Domain\ResolvedRule;
use App\Modules\Pricing\Infrastructure\Models\ExchangeRate;
use App\Modules\Pricing\Infrastructure\Models\PricingRule;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/** Pricing rules, exchange rates and the price simulator (pricing.manage). */
final class PricingController
{
    public function __construct(private readonly AuditLogger $audit, private readonly CountryDirectory $countries) {}

    public function rules(): JsonResponse
    {
        return ApiResponse::success(PricingRule::query()->orderBy('scope')->orderBy('priority')->get()->map(fn ($r) => $this->present($r))->all());
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $rule = PricingRule::query()->create($data);
        $this->audit->record('pricing_rule.created', 'pricing_rule', $rule->id, null, $data);
        Artisan::queue('pricing:reprice');

        return ApiResponse::success($this->present($rule), 201);
    }

    public function updateRule(Request $request, PricingRule $pricingRule): JsonResponse
    {
        $data = $this->validated($request, false);
        $old = $pricingRule->only(array_keys($data));
        $pricingRule->update($data);
        $this->audit->record('pricing_rule.updated', 'pricing_rule', $pricingRule->id, $old, $data);
        Artisan::queue('pricing:reprice');

        return ApiResponse::success($this->present($pricingRule));
    }

    public function simulate(Request $request, PricingEngine $engine): JsonResponse
    {
        $d = $request->validate([
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'shipping_cost' => ['sometimes', 'numeric', 'min:0'],
            'cost_currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'sale_currency' => ['required', 'in:AED,SAR'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'fx_buffer_percent' => ['sometimes', 'numeric', 'between:0,50'],
            'import_vat_percent' => ['sometimes', 'numeric', 'between:0,50'],
            'operational_allocation' => ['sometimes', 'numeric', 'min:0'],
            'vat_percent' => ['required', 'numeric', 'between:0,50'],
            'payment_fee_percent' => ['sometimes', 'numeric', 'between:0,20'],
            'payment_fee_fixed' => ['sometimes', 'numeric', 'min:0'],
            'rule_type' => ['required', 'in:margin_percent,markup_percent,fixed_markup'],
            'value' => ['required', 'numeric'],
            'min_margin_percent' => ['nullable', 'numeric', 'between:0,90'],
            'max_selling_price' => ['nullable', 'numeric', 'min:0'],
            'rounding' => ['sometimes', 'in:ceil_whole,ceil_x9,ceil_0_05,ceil_cent'],
        ]);
        $s = fn (string $k, string $default = '0') => isset($d[$k]) ? (string) $d[$k] : $default;

        try {
            $result = $engine->price(new PricingInput(
                $s('unit_cost'), $s('shipping_cost'), $d['cost_currency'], $d['sale_currency'], $s('exchange_rate'),
                $s('fx_buffer_percent'), $s('import_vat_percent'), $s('operational_allocation'), $s('vat_percent'),
                $s('payment_fee_percent'), $s('payment_fee_fixed'),
                new ResolvedRule($d['rule_type'], $s('value'), $d['min_margin_percent'] ?? null ? (string) $d['min_margin_percent'] : null, isset($d['max_selling_price']) ? (string) $d['max_selling_price'] : null),
                $s('rounding', 'ceil_whole'),
            ));
        } catch (PricingException $e) {
            throw new ApiException('PRICING_REJECTED', 422, ['reason' => $e->reason], $e->getMessage());
        }

        return ApiResponse::success($result->toArray());
    }

    public function rates(): JsonResponse
    {
        $latest = ExchangeRate::query()->orderByDesc('effective_at')->limit(200)->get()
            ->unique(fn ($r) => $r->base_currency.$r->target_currency)->values();

        return ApiResponse::success($latest->map(fn ($r) => [
            'id' => $r->id, 'base' => $r->base_currency, 'target' => $r->target_currency, 'rate' => $r->rate,
            'source' => $r->source, 'effective_at' => $r->effective_at->toIso8601String(),
            'stale' => $r->effective_at->lt(now()->subHours((int) config('gulfcart.fx.max_age_hours', 24))),
        ])->all());
    }

    public function storeRate(Request $request): JsonResponse
    {
        $d = $request->validate([
            'base' => ['required', 'regex:/^[A-Z]{3}$/'],
            'target' => ['required', 'regex:/^[A-Z]{3}$/', 'different:base'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);
        $rate = ExchangeRate::query()->create([
            'base_currency' => $d['base'], 'target_currency' => $d['target'], 'rate' => (string) $d['rate'],
            'source' => 'manual', 'effective_at' => now(),
        ]);
        $this->audit->record('exchange_rate.manual', 'exchange_rate', $rate->id, null, $d);
        Artisan::queue('pricing:reprice');

        return ApiResponse::success(['id' => $rate->id, 'base' => $rate->base_currency, 'target' => $rate->target_currency, 'rate' => $rate->rate], 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'scope' => [$creating ? 'required' : 'prohibited', 'in:global,country,supplier,category,product'],
            'country' => ['nullable', 'string', 'size:2'],
            'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id', 'required_if:scope,supplier'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id', 'required_if:scope,category'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id', 'required_if:scope,product'],
            'rule_type' => [$req, 'in:margin_percent,markup_percent,fixed_markup'],
            'value' => [$req, 'numeric', 'between:-1000,100000'],
            'min_margin_percent' => ['nullable', 'numeric', 'between:0,90'],
            'max_selling_price' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['sometimes', 'integer', 'between:1,1000'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        if (($data['rule_type'] ?? null) === 'margin_percent' && ((float) $data['value'] <= 0 || (float) $data['value'] >= 90)) {
            throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['value' => ['Target margin must be between 0 and 90 percent.']]]);
        }
        if (array_key_exists('country', $data)) {
            $code = $data['country'];
            unset($data['country']);
            $data['country_id'] = $code === null ? null : ($this->countries->find($code)?->id ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422));
        }
        if (($data['scope'] ?? null) === 'country' && empty($data['country_id'])) {
            throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['country' => ['A country rule needs a country.']]]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(PricingRule $r): array
    {
        $code = null;
        foreach ($this->countries->active() as $c) {
            $code = $c->id === $r->country_id ? $c->code : $code;
        }

        return ['id' => $r->id, 'country' => $code] + $r->only(['scope', 'supplier_id', 'category_id', 'product_id', 'rule_type', 'value', 'min_margin_percent', 'max_selling_price', 'priority', 'is_active']);
    }
}
