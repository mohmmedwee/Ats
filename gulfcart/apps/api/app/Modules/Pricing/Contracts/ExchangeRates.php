<?php

namespace App\Modules\Pricing\Contracts;

interface ExchangeRates
{
    /** @return array{rate: string, id: string|null, effective_at: string|null}|null null when missing or stale */
    public function rate(string $base, string $target): ?array;
}
