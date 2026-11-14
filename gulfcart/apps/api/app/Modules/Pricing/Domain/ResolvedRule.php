<?php

namespace App\Modules\Pricing\Domain;

final class ResolvedRule
{
    /** @param list<string> $ruleIds */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly ?string $minMarginPercent,
        public readonly ?string $maxSellingPrice,
        public readonly array $ruleIds = [],
    ) {}
}
