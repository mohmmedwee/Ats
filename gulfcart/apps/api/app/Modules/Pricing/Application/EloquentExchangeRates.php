<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\ExchangeRateProvider;
use App\Modules\Pricing\Contracts\ExchangeRates;
use App\Modules\Pricing\Infrastructure\Models\ExchangeRate;
use App\Shared\Money\Decimal;

final class EloquentExchangeRates implements ExchangeRates
{
    public function rate(string $base, string $target): ?array
    {
        $base = strtoupper($base);
        $target = strtoupper($target);
        if ($base === $target) {
            return ['rate' => '1', 'id' => null, 'effective_at' => null];
        }

        $maxAge = now()->subHours((int) config('gulfcart.fx.max_age_hours', 24));
        $direct = $this->latest($base, $target);
        if ($direct !== null) {
            return $direct->effective_at->lt($maxAge) ? null : ['rate' => (string) $direct->rate, 'id' => $direct->id, 'effective_at' => $direct->effective_at->toIso8601String()];
        }

        $inverse = $this->latest($target, $base);
        if ($inverse !== null && ! $inverse->effective_at->lt($maxAge)) {
            return ['rate' => Decimal::div('1', (string) $inverse->rate, 8), 'id' => $inverse->id, 'effective_at' => $inverse->effective_at->toIso8601String()];
        }

        return null;
    }

    /** Stores a fresh snapshot from the provider; returns the number of pairs stored. */
    public function refresh(ExchangeRateProvider $provider): int
    {
        $now = now();
        $count = 0;
        foreach ($provider->fetch() as $row) {
            if (Decimal::cmp($row['rate'], '0') <= 0) {
                continue;
            }
            ExchangeRate::query()->create([
                'base_currency' => strtoupper($row['base']), 'target_currency' => strtoupper($row['target']),
                'rate' => $row['rate'], 'source' => $provider->name(), 'effective_at' => $now,
            ]);
            $count++;
        }

        return $count;
    }

    private function latest(string $base, string $target): ?ExchangeRate
    {
        return ExchangeRate::query()->where('base_currency', $base)->where('target_currency', $target)
            ->where('effective_at', '<=', now())->orderByDesc('effective_at')->first();
    }
}
