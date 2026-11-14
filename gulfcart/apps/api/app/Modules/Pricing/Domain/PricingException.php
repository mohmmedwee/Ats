<?php

namespace App\Modules\Pricing\Domain;

use RuntimeException;

/** The configuration cannot produce a valid price (e.g. margin + fees ≥ 100%, above max price). */
final class PricingException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
