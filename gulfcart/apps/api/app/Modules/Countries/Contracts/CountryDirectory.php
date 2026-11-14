<?php

namespace App\Modules\Countries\Contracts;

use App\Modules\Countries\Domain\CountryData;

interface CountryDirectory
{
    /** Active country by ISO 3166-1 alpha-2 code, or null. */
    public function find(string $code): ?CountryData;

    /** @return list<CountryData> */
    public function active(): array;

    public function default(): CountryData;

    public function forget(): void;
}
