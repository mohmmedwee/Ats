<?php

namespace App\Modules\Audit\Contracts;

interface AuditLogger
{
    /**
     * Append an audit entry. The acting user, IP, user agent and request ID
     * are taken from the current request unless $actorId is given.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(string $action, string $entityType, ?string $entityId = null, ?array $old = null, ?array $new = null, ?string $actorId = null): void;
}
