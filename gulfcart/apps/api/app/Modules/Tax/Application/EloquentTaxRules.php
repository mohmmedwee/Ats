<?php

namespace App\Modules\Tax\Application;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Tax\Contracts\TaxRules;
use App\Modules\Tax\Domain\DutyAssessment;
use App\Modules\Tax\Infrastructure\Models\CustomsRule;
use App\Modules\Tax\Infrastructure\Models\TaxRule;
use App\Shared\Exceptions\ApiException;
use App\Shared\Money\Decimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class EloquentTaxRules implements TaxRules
{
    public function __construct(private readonly CountryDirectory $countries) {}

    public function vatRate(string $countryCode, array $categoryLineage = [], ?DateTimeInterface $on = null): string
    {
        $rules = $this->effective(TaxRule::query(), $countryCode, $on)->where('tax_type', 'vat')->get();
        foreach ($categoryLineage as $categoryId) {
            $match = $rules->firstWhere('category_id', $categoryId);
            if ($match !== null) {
                return (string) $match->rate_percent;
            }
        }
        $default = $rules->firstWhere('category_id', null)
            ?? throw new ApiException('TAX_NOT_CONFIGURED', 503, ['country' => $countryCode]);

        return (string) $default->rate_percent;
    }

    public function shippingVatRate(string $countryCode, ?DateTimeInterface $on = null): string
    {
        $rule = $this->effective(TaxRule::query(), $countryCode, $on)->where('tax_type', 'vat')->whereNull('category_id')->first();

        return $rule !== null && $rule->applies_to_shipping ? (string) $rule->rate_percent : '0';
    }

    public function nonRecoverableImportVatRate(string $countryCode, ?DateTimeInterface $on = null): string
    {
        $rule = $this->effective(TaxRule::query(), $countryCode, $on)->where('tax_type', 'import_vat')->whereNull('category_id')->first();

        return $rule === null || $rule->is_recoverable ? '0' : (string) $rule->rate_percent;
    }

    public function dutyForShipment(string $countryCode, array $lines, string $currency, ?DateTimeInterface $on = null): DutyAssessment
    {
        /** @var Collection<int, CustomsRule> $rules */
        $rules = $this->effective(CustomsRule::query(), $countryCode, $on)->get();
        $value = Decimal::sum(array_map(fn ($l) => $l->customsValue, $lines), 2);
        $default = $rules->firstWhere('hs_code_prefix', null);
        $threshold = $default?->de_minimis_amount;

        if ($threshold !== null && ($default->de_minimis_currency ?? $currency) === $currency && Decimal::cmp($value, (string) $threshold) <= 0) {
            return new DutyAssessment('0.00', $value, true, (string) $threshold);
        }

        $duty = '0';
        foreach ($lines as $line) {
            $rule = $this->ruleForHs($rules, $line->hsCode) ?? $default;
            if ($rule !== null) {
                $duty = Decimal::add($duty, Decimal::div(Decimal::mul($line->customsValue, (string) $rule->duty_percent, 6), '100', 6), 6);
            }
        }

        return new DutyAssessment(Decimal::round($duty, 2), $value, false, $threshold !== null ? (string) $threshold : null);
    }

    /** @param Collection<int, CustomsRule> $rules */
    private function ruleForHs(Collection $rules, ?string $hs): ?CustomsRule
    {
        if ($hs === null) {
            return null;
        }

        return $rules->filter(fn ($r) => $r->hs_code_prefix !== null && str_starts_with($hs, $r->hs_code_prefix))
            ->sortByDesc(fn ($r) => strlen((string) $r->hs_code_prefix))->first();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function effective(Builder $query, string $countryCode, ?DateTimeInterface $on): Builder
    {
        $country = $this->countries->find($countryCode) ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422, ['country' => $countryCode]);
        $date = Carbon::instance($on ?? now())->toDateString();

        return $query->where('country_id', $country->id)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from');
    }
}
