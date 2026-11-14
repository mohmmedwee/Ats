<?php

namespace App\Modules\Tax\Domain;

/** One line of a shipment for customs: customs value already in the destination currency. */
final class DutyLine
{
    public function __construct(public readonly ?string $hsCode, public readonly string $customsValue) {}
}
