<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail is append-only: the database itself refuses UPDATE and DELETE on audit_logs,
 * whatever the application (or an attacker with its credentials) tries. Retention clean-up, if ever
 * needed, is done by a DBA disabling the trigger deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only (% refused)', TG_OP USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audit_logs_no_change ON audit_logs;
            CREATE TRIGGER audit_logs_no_change BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
            DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
            CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_change ON audit_logs; DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_append_only();');
    }
};
