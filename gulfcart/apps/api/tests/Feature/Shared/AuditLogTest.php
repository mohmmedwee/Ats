<?php

namespace Tests\Feature\Shared;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use LogicException;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    public function test_secrets_are_redacted(): void
    {
        app(AuditLogger::class)->record('user.updated', 'user', null, ['password' => 'old'], ['password' => 'new', 'name' => 'Sara']);

        $log = AuditLog::query()->sole();
        $this->assertSame('[redacted]', $log->new_values['password'] ?? null);
        $this->assertSame('Sara', $log->new_values['name'] ?? null);
    }

    public function test_audit_logs_are_append_only(): void
    {
        app(AuditLogger::class)->record('x', 'user');
        $log = AuditLog::query()->sole();

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }
}
