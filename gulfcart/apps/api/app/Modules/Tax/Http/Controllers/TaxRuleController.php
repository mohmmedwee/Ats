<?php

namespace App\Modules\Tax\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Tax\Infrastructure\Models\CustomsRule;
use App\Modules\Tax\Infrastructure\Models\TaxRule;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Country tax and customs configuration (countries.manage). Changes are audited and effective-dated. */
final class TaxRuleController
{
    public function __construct(private readonly AuditLogger $audit, private readonly CountryDirectory $countries) {}

    public function taxIndex(): JsonResponse
    {
        return ApiResponse::success(TaxRule::query()->orderBy('country_id')->orderByDesc('effective_from')->get()->map(fn ($r) => $this->tax($r))->all());
    }

    public function taxStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'tax_type' => ['required', 'in:vat,import_vat'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'rate_percent' => ['required', 'numeric', 'between:0,99.9999'],
            'is_recoverable' => ['sometimes', 'boolean'],
            'applies_to_shipping' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $rule = TaxRule::query()->create(collect($data)->except('country')->all() + ['country_id' => $this->countryId($data['country'])]);
        $this->audit->record('tax_rule.created', 'tax_rule', $rule->id, null, $data);

        return ApiResponse::success($this->tax($rule), 201);
    }

    public function taxUpdate(Request $request, TaxRule $taxRule): JsonResponse
    {
        $data = $request->validate([
            'rate_percent' => ['sometimes', 'numeric', 'between:0,99.9999'],
            'is_recoverable' => ['sometimes', 'boolean'],
            'applies_to_shipping' => ['sometimes', 'boolean'],
            'effective_to' => ['nullable', 'date'],
        ]);
        $old = $taxRule->only(array_keys($data));
        $taxRule->update($data);
        $this->audit->record('tax_rule.updated', 'tax_rule', $taxRule->id, $old, $data);

        return ApiResponse::success($this->tax($taxRule));
    }

    public function customsIndex(): JsonResponse
    {
        return ApiResponse::success(CustomsRule::query()->orderBy('country_id')->orderByDesc('effective_from')->get()->map(fn ($r) => $this->customs($r))->all());
    }

    public function customsStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'hs_code_prefix' => ['nullable', 'regex:/^\d{2,12}$/'],
            'duty_percent' => ['required', 'numeric', 'between:0,100'],
            'valuation_basis' => ['sometimes', 'in:CIF,FOB'],
            'de_minimis_amount' => ['nullable', 'numeric', 'min:0'],
            'de_minimis_currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $rule = CustomsRule::query()->create(collect($data)->except('country')->all() + ['country_id' => $this->countryId($data['country'])]);
        $this->audit->record('customs_rule.created', 'customs_rule', $rule->id, null, $data);

        return ApiResponse::success($this->customs($rule), 201);
    }

    public function customsUpdate(Request $request, CustomsRule $customsRule): JsonResponse
    {
        $data = $request->validate([
            'duty_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'de_minimis_amount' => ['nullable', 'numeric', 'min:0'],
            'effective_to' => ['nullable', 'date'],
        ]);
        $old = $customsRule->only(array_keys($data));
        $customsRule->update($data);
        $this->audit->record('customs_rule.updated', 'customs_rule', $customsRule->id, $old, $data);

        return ApiResponse::success($this->customs($customsRule));
    }

    private function countryId(string $code): string
    {
        return ($this->countries->find($code) ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422, ['country' => strtoupper($code)]))->id;
    }

    private function code(string $countryId): ?string
    {
        foreach ($this->countries->active() as $c) {
            if ($c->id === $countryId) {
                return $c->code;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function tax(TaxRule $r): array
    {
        return ['id' => $r->id, 'country' => $this->code($r->country_id), 'tax_type' => $r->tax_type, 'category_id' => $r->category_id,
            'rate_percent' => $r->rate_percent, 'is_recoverable' => $r->is_recoverable, 'applies_to_shipping' => $r->applies_to_shipping,
            'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString()];
    }

    /** @return array<string, mixed> */
    private function customs(CustomsRule $r): array
    {
        return ['id' => $r->id, 'country' => $this->code($r->country_id), 'hs_code_prefix' => $r->hs_code_prefix, 'duty_percent' => $r->duty_percent,
            'valuation_basis' => $r->valuation_basis, 'de_minimis_amount' => $r->de_minimis_amount, 'de_minimis_currency' => $r->de_minimis_currency,
            'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString()];
    }
}
