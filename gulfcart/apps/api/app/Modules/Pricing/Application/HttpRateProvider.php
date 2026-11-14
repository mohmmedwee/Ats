<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\ExchangeRateProvider;
use App\Modules\Pricing\Infrastructure\Models\ExchangeRate;
use App\Shared\Money\Decimal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live rates from a JSON endpoint returning {"rates": {"AED": 3.6725, ...}} for a base currency
 * (e.g. https://open.er-api.com/v6/latest/{base}). Sanity guard: a pair whose rate moved more than
 * `max_change_percent` since the last stored rate is skipped and logged (protects against a bad feed;
 * prices then expire safely instead of being repriced wrongly).
 */
final class HttpRateProvider implements ExchangeRateProvider
{
    public function name(): string
    {
        return 'http:'.(parse_url((string) config('gulfcart.fx.http.url'), PHP_URL_HOST) ?: 'unknown');
    }

    public function fetch(): array
    {
        $url = (string) config('gulfcart.fx.http.url');
        $targets = (array) config('gulfcart.fx.http.targets', ['AED', 'SAR']);
        $maxChange = (string) config('gulfcart.fx.http.max_change_percent', '10');
        $out = [];

        foreach ((array) config('gulfcart.fx.http.bases', ['USD']) as $base) {
            try {
                $response = Http::timeout(10)->retry(2, 500)->acceptJson()->get(str_replace('{base}', $base, $url));
                $rates = (array) $response->json('rates', []);
            } catch (Throwable $e) {
                Log::error('fx.fetch_failed', ['base' => $base, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($targets as $target) {
                if ($target === $base || ! isset($rates[$target]) || ! is_numeric($rates[$target])) {
                    continue;
                }
                $rate = number_format((float) $rates[$target], 8, '.', '');
                $previous = ExchangeRate::query()->where('base_currency', $base)->where('target_currency', $target)->orderByDesc('effective_at')->value('rate');
                if ($previous !== null) {
                    $change = Decimal::mul(Decimal::div(Decimal::sub($rate, (string) $previous, 8), (string) $previous, 8), '100', 4);
                    if (Decimal::cmp(ltrim($change, '-'), $maxChange) > 0) {
                        Log::critical('fx.rejected_outlier', ['pair' => "{$base}/{$target}", 'previous' => $previous, 'received' => $rate]);

                        continue;
                    }
                }
                $out[] = ['base' => $base, 'target' => $target, 'rate' => $rate];
            }
        }

        return $out;
    }
}
