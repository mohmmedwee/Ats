<?php

namespace App\Modules\Returns\Domain;

use App\Shared\StateMachine\StateMachine;

final class ReturnStatus extends StateMachine
{
    protected const TRANSITIONS = [
        'requested' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['received', 'cancelled'],
        'received' => ['refunded', 'rejected'],
        'rejected' => [],
        'refunded' => [],
        'cancelled' => [],
    ];

    /** States that still reserve quantity against new return requests. */
    public const OPEN = ['requested', 'approved', 'received'];

    protected static function entity(): string
    {
        return 'return';
    }
}
