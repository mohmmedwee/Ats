<?php

namespace App\Modules\Countries\Domain;

/** Read-only country configuration exposed to other modules. */
final class CountryData
{
    /**
     * @param  array{en: string, ar: string}  $name
     * @param  list<string>  $supportedLocales
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly array $name,
        public readonly string $currency,
        public readonly string $defaultLocale,
        public readonly array $supportedLocales,
        public readonly string $timezone,
        public readonly array $settings,
    ) {}

    public function localizedName(string $locale): string
    {
        return $this->name[$locale] ?? $this->name['en'];
    }

    /** @return array<string, mixed> */
    public function toPublicArray(string $locale): array
    {
        return [
            'code' => $this->code,
            'name' => $this->localizedName($locale),
            'currency' => $this->currency,
            'default_locale' => $this->defaultLocale,
            'supported_locales' => $this->supportedLocales,
            'timezone' => $this->timezone,
            'return_window_days' => (int) ($this->settings['returns']['window_days'] ?? config('gulfcart.returns.default_window_days', 14)),
        ];
    }
}
