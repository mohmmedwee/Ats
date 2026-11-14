<?php

namespace App\Modules\Catalog\Domain;

/** Product data coming from a supplier feed or CSV import. */
final class ProductDraft
{
    /**
     * @param  array<string, string|null>  $title
     * @param  array<string, string|null>|null  $description
     * @param  list<string>  $countryCodes
     */
    public function __construct(
        public readonly array $title,
        public readonly ?array $description,
        public readonly ?string $categorySlug,
        public readonly ?string $brand,
        public readonly ?int $weightGrams,
        public readonly ?string $hsCode,
        public readonly array $countryCodes,
    ) {}
}
