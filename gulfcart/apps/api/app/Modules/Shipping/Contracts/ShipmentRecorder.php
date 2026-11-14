<?php

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\Domain\ShipmentUpdate;

/** Used by Procurement to record supplier tracking as shipments (idempotent per supplier order + tracking number). */
interface ShipmentRecorder
{
    public function record(ShipmentUpdate $update): string;
}
