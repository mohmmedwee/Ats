<?php

namespace App\Shared\StateMachine;

use App\Shared\Exceptions\ApiException;

/**
 * Explicit allowed transitions for an entity status (docs/PROCUREMENT_WORKFLOW.md §6.3).
 * Subclasses declare TRANSITIONS as [from => [to, ...]].
 */
abstract class StateMachine
{
    /** @var array<string, list<string>> */
    protected const TRANSITIONS = [];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, static::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (! static::canTransition($from, $to)) {
            throw new ApiException('ILLEGAL_STATE_TRANSITION', 409, [
                'entity' => static::entity(),
                'from' => $from,
                'to' => $to,
            ]);
        }
    }

    /** @return list<string> */
    public static function states(): array
    {
        $states = array_keys(static::TRANSITIONS);
        foreach (static::TRANSITIONS as $targets) {
            $states = array_merge($states, $targets);
        }

        return array_values(array_unique($states));
    }

    abstract protected static function entity(): string;
}
