<?php

namespace App\Modules\Procurement\Console;

final class ProcurementReconcileCommand extends ProcurementCommands
{
    protected const ACTION = 'reconcile';

    protected $signature = 'procurement:reconcile';

    protected $description = 'Reconcile supplier orders with unknown outcomes';
}
