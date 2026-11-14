<?php

namespace App\Modules\Pricing\Contracts;

/** Source of exchange rates for `fx:refresh`. Replace the configured provider with a live feed before launch. */
interface ExchangeRateProvider
{
    public function name(): string;

    /** @return list<array{base: string, target: string, rate: string}> */
    public function fetch(): array;
}
