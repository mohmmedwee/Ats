<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 100);
            $table->uuid('aggregate_id');
            $table->string('event_type', 150);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('dispatched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_status_check CHECK (status IN ('pending','dispatched','failed'))");
        DB::statement("CREATE INDEX idx_outbox_pending ON outbox_events(available_at) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
