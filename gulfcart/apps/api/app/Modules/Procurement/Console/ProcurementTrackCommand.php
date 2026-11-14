<?php

namespace App\Modules\Procurement\Console;

final class ProcurementTrackCommand extends ProcurementCommands
{
    protected const ACTION = 'track';

    protected $signature = 'procurement:track';

    protected $description = 'Pull supplier status and tracking for purchased supplier orders';
}
