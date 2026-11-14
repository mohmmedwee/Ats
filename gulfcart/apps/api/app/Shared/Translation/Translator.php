<?php

namespace App\Shared\Translation;

/** Machine translation of short catalog text (English to Arabic). Results are drafts for human review. */
interface Translator
{
    public function enabled(): bool;

    /**
     * @param  array<string, string>  $fields  key => English text (e.g. title, description)
     * @return array<string, string> key => Arabic text; keys may be missing when translation failed
     */
    public function toArabic(array $fields): array;
}
