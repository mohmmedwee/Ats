<?php

namespace App\Modules\Tax\Contracts;

use App\Modules\Tax\Domain\DutyAssessment;
use App\Modules\Tax\Domain\DutyLine;
use DateTimeInterface;

interface TaxRules
{
    /**
     * Output VAT rate (percent, e.g. "5.0000") for a category lineage (nearest first);
     * falls back to the country default.
     *
     * @param  list<string>  $categoryLineage
     */
    public function vatRate(string $countryCode, array $categoryLineage = [], ?DateTimeInterface $on = null): string;

    /** Rate applied to the customer shipping fee (standard rate or 0). */
    public function shippingVatRate(string $countryCode, ?DateTimeInterface $on = null): string;

    /** Import VAT that is a real cost (0 when recoverable for GulfCart's registration). */
    public function nonRecoverableImportVatRate(string $countryCode, ?DateTimeInterface $on = null): string;

    /**
     * Duty for one supplier shipment. Values are in the country currency. When the
     * shipment value is at or below the de minimis threshold, duty is zero.
     *
     * @param  list<DutyLine>  $lines
     */
    public function dutyForShipment(string $countryCode, array $lines, string $currency, ?DateTimeInterface $on = null): DutyAssessment;
}
