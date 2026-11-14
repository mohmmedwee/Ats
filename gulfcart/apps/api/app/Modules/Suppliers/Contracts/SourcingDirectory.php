<?php

namespace App\Modules\Suppliers\Contracts;

use App\Modules\Suppliers\Domain\SourceData;

interface SourcingDirectory
{
    /**
     * Eligible sources for a variant in a country, best first (priority, then cost).
     * Eligible = supplier active + authorized for resale in that country today,
     * supplier product active, variant mapped, not out of stock.
     *
     * @return list<SourceData>
     */
    public function sourcesFor(string $variantId, string $countryCode): array;

    /** A specific source regardless of eligibility (used for existing orders). */
    public function source(string $supplierProductId, string $variantId): ?SourceData;
}
