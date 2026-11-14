<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DatabaseAuditLogger implements AuditLogger
{
    /** Keys never written to audit values. */
    private const REDACT = ['password', 'mfa_secret', 'mfa_recovery_codes', 'remember_token', 'token'];

    public function record(string $action, string $entityType, ?string $entityId = null, ?array $old = null, ?array $new = null, ?string $actorId = null): void
    {
        $request = app('request');
        $request = $request instanceof Request ? $request : null;
        $actor = $actorId ?? Auth::id();

        AuditLog::query()->create([
            'user_id' => is_string($actor) ? $actor : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $this->redact($old),
            'new_values' => $this->redact($new),
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'request_id' => $request?->attributes->get('request_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach (self::REDACT as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
