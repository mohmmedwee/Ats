<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\ResolvedRule;
use App\Modules\Pricing\Infrastructure\Models\PricingRule;
use App\Shared\Money\Decimal;
use Illuminate\Support\Collection;

/**
 * Most specific active rule wins for the markup/margin (product > category, nearest first
 * > supplier > country > global). min_margin_percent and max_selling_price are taken as the
 * strictest values across all matching rules. A country-agnostic rule applies to all countries.
 */
final class RuleResolver
{
    /** @var Collection<int, PricingRule>|null */
    private ?Collection $rules = null;

    /** @param list<string> $categoryLineage */
    public function resolve(string $countryId, string $supplierId, array $categoryLineage, string $productId): ?ResolvedRule
    {
        $now = now();
        $active = $this->rules()->filter(fn (PricingRule $r) => ($r->starts_at === null || $r->starts_at->lte($now))
            && ($r->ends_at === null || $r->ends_at->gte($now))
            && ($r->country_id === null || $r->country_id === $countryId));

        $matching = $active->filter(fn (PricingRule $r) => match ($r->scope) {
            'product' => $r->product_id === $productId,
            'category' => in_array($r->category_id, $categoryLineage, true),
            'supplier' => $r->supplier_id === $supplierId,
            'country', 'global' => true,
            default => false,
        });

        if ($matching->isEmpty()) {
            return null;
        }

        $rank = fn (PricingRule $r) => match ($r->scope) {
            'product' => 0,
            'category' => 1 + (int) array_search($r->category_id, $categoryLineage, true),
            'supplier' => 100,
            'country' => 200,
            default => 300,
        };
        $winner = $matching->sortBy(fn (PricingRule $r) => [$rank($r), $r->country_id === null ? 1 : 0, $r->priority])->first();

        $minMargin = $matching->pluck('min_margin_percent')->filter(fn ($v) => $v !== null)->reduce(fn ($carry, $v) => $carry === null ? (string) $v : Decimal::max($carry, (string) $v));
        $maxPrice = $matching->pluck('max_selling_price')->filter(fn ($v) => $v !== null)->reduce(fn ($carry, $v) => $carry === null ? (string) $v : (Decimal::cmp((string) $v, $carry) < 0 ? (string) $v : $carry));

        return new ResolvedRule($winner->rule_type, (string) $winner->value, $minMargin, $maxPrice, $matching->pluck('id')->values()->all());
    }

    public function flush(): void
    {
        $this->rules = null;
    }

    /** @return Collection<int, PricingRule> */
    private function rules(): Collection
    {
        return $this->rules ??= PricingRule::query()->where('is_active', true)->get();
    }
}
