<?php

namespace App\Modules\Procurement\Console;

final class ProcurementRunCommand extends ProcurementCommands
{
    protected const ACTION = 'run';

    protected $signature = 'procurement:run';

    protected $description = 'Process due procurement tasks and release stale claims';
}
