<?php

namespace App\Shared\Translation;

final class NullTranslator implements Translator
{
    public function enabled(): bool
    {
        return false;
    }

    public function toArabic(array $fields): array
    {
        return [];
    }
}
