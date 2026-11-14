<?php

namespace App\Modules\Countries\Contracts;

/** Country-specific address validation (docs/COUNTRY_CONFIGURATION.md: registered strategies). */
interface AddressRules
{
    /** @return array<string, mixed> Laravel validation rules for address fields */
    public function rules(string $countryCode): array;

    /** @return list<array{code: string, en: string, ar: string}> regions/emirates for forms */
    public function regions(string $countryCode): array;

    public function normalizePhone(string $countryCode, string $phone): string;
}
