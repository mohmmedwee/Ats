<?php

namespace App\Modules\Tax\Domain;

final class DutyAssessment
{
    public function __construct(
        public readonly string $amount,
        public readonly string $customsValue,
        public readonly bool $deMinimisApplied,
        public readonly ?string $deMinimis,
    ) {}
}
