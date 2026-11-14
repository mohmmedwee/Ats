<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Sign-in codes can be sent to a number that has no account yet (express checkout). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE otp_codes ALTER COLUMN user_id DROP NOT NULL');
        DB::statement('CREATE INDEX otp_codes_phone_purpose_idx ON otp_codes (phone, purpose)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS otp_codes_phone_purpose_idx');
        DB::statement('DELETE FROM otp_codes WHERE user_id IS NULL');
        DB::statement('ALTER TABLE otp_codes ALTER COLUMN user_id SET NOT NULL');
    }
};
