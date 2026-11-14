<?php

namespace App\Modules\Pricing\Listeners;

use App\Modules\Pricing\Application\Repricer;
use App\Modules\Suppliers\Events\SourcingChanged;

final class RepriceProduct
{
    public function __construct(private readonly Repricer $repricer) {}

    public function handle(SourcingChanged $event): void
    {
        $this->repricer->reprice($event->productId, notify: false);
    }
}
