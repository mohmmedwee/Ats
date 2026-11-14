<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('channel', 20);
            $table->string('template', 100);
            $table->string('locale', 10);
            $table->char('recipient_hash', 64);
            $table->string('related_type', 100)->nullable();
            $table->uuid('related_id')->nullable();
            $table->string('dedupe_key', 255)->unique();
            $table->string('provider_message_id', 255)->nullable();
            $table->string('status', 30);
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['related_type', 'related_id']);
        });
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_channel_check CHECK (channel IN ('email','sms','whatsapp','push'))");
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_status_check CHECK (status IN ('sent','failed','skipped_no_provider','skipped_no_contact'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
