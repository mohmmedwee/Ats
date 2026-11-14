<?php

namespace App\Shared\I18n;

/** Helpers for translatable JSONB fields shaped like {"en": "...", "ar": "..."}. */
final class Translations
{
    /**
     * @param  array<string, string|null>|null  $values
     */
    public static function pick(?array $values, ?string $locale = null): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }
        $locale ??= app()->getLocale();

        return $values[$locale] ?? $values['en'] ?? $values[array_key_first($values)];
    }

    /** Validation rules for a translatable field: English required, Arabic optional or required. @return array<string, list<string>> */
    public static function rules(string $field, bool $required = true, bool $arabicRequired = false, int $max = 255): array
    {
        $base = $required ? 'required' : 'sometimes';

        return [
            $field => [$base, 'array'],
            "{$field}.en" => [$required ? 'required_with:'.$field : 'nullable', 'string', "max:{$max}"],
            "{$field}.ar" => [$arabicRequired ? 'required_with:'.$field : 'nullable', 'string', "max:{$max}"],
        ];
    }
}
