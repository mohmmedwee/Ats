<?php

namespace App\Modules\Countries\Application;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Countries\Infrastructure\Models\Country;
use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;

final class EloquentCountryDirectory implements CountryDirectory
{
    private const CACHE_KEY = 'countries.active.v1';

    /** @var array<string, CountryData>|null */
    private ?array $memo = null;

    public function __construct(private readonly Cache $cache) {}

    public function find(string $code): ?CountryData
    {
        return $this->all()[strtoupper($code)] ?? null;
    }

    public function active(): array
    {
        return array_values($this->all());
    }

    public function default(): CountryData
    {
        $code = (string) config('gulfcart.default_country', 'AE');

        return $this->find($code) ?? throw new RuntimeException("Default country [{$code}] is not configured or inactive.");
    }

    public function forget(): void
    {
        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, CountryData> */
    private function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        // Cache plain arrays: the cache must not unserialize objects (cache.serializable_classes = false).
        /** @var array<string, array<string, mixed>> $rows */
        $rows = $this->cache->remember(self::CACHE_KEY, 600, static function (): array {
            $map = [];
            foreach (Country::query()->where('is_active', true)->orderBy('code')->get() as $c) {
                $map[$c->code] = [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'currency' => $c->currency_code,
                    'default_locale' => $c->default_locale, 'supported_locales' => $c->supportedLocaleList(),
                    'timezone' => $c->timezone, 'settings' => $c->settings,
                ];
            }

            return $map;
        });

        $countries = [];
        foreach ($rows as $code => $r) {
            $countries[$code] = new CountryData(
                $r['id'], $r['code'], $r['name'], $r['currency'], $r['default_locale'],
                $r['supported_locales'], $r['timezone'], $r['settings'],
            );
        }

        return $this->memo = $countries;
    }
}
