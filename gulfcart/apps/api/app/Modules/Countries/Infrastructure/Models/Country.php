<?php

namespace App\Modules\Countries\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property array{en: string, ar: string} $name
 * @property string $currency_code
 * @property string $default_locale
 * @property string $supported_locales
 * @property string $timezone
 * @property array<string, mixed> $settings
 * @property bool $is_active
 */
class Country extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'settings' => 'array', 'is_active' => 'boolean'];
    }

    /** @return list<string> Parses the PostgreSQL text[] literal, e.g. {ar-AE,en-AE}. */
    public function supportedLocaleList(): array
    {
        $raw = trim((string) $this->getRawOriginal('supported_locales'), '{}');

        return $raw === '' ? [] : array_values(array_map(static fn (string $v): string => trim($v, '"'), explode(',', $raw)));
    }
}
