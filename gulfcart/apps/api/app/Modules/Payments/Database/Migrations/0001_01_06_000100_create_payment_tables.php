<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->string('provider', 50);
            $table->string('provider_reference', 255)->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 30);
            $table->decimal('refunded_amount', 14, 2)->default(0);
            $table->string('method', 30)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('redirect_url')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_reference']);
            $table->index('order_id');
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('created','requires_action','authorized','captured','failed','cancelled'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0 AND refunded_amount >= 0 AND refunded_amount <= amount)');

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('provider_event_id', 255);
            $table->string('event_type', 100);
            $table->jsonb('payload');
            $table->string('status', 20)->default('received');
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('received_at')->useCurrent();
            $table->unique(['provider', 'provider_event_id']);
        });
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT pwe_status_check CHECK (status IN ('received','processed','ignored','failed'))");

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->uuid('return_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('reason_code', 50);
            $table->text('reason')->nullable();
            $table->jsonb('item_quantities')->default('{}');
            $table->string('status', 30);
            $table->string('idempotency_key', 255)->unique();
            $table->string('provider_reference', 255)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();
            $table->index('payment_id');
            $table->index('order_id');
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('requested','approved','processing','succeeded','failed','rejected'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');
    }
};
