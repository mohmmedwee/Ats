<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS supplier_order_number_seq START 10001');

        Schema::create('supplier_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 50)->unique();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->smallInteger('shipment_group');
            $table->string('external_order_id', 255)->nullable();
            $table->string('status', 40);
            $table->string('idempotency_key', 255)->unique();
            $table->smallInteger('key_version')->default(1);
            $table->string('procurement_mode', 20)->default('automated');
            $table->decimal('expected_cost', 14, 4);
            $table->decimal('actual_cost', 14, 4)->nullable();
            $table->char('cost_currency', 3);
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->jsonb('shipping_address');
            $table->jsonb('supplier_response')->default('{}');
            $table->string('exception_reason', 60)->nullable();
            $table->jsonb('exception_details')->nullable();
            $table->timestampTz('purchased_at')->nullable();
            $table->timestampTz('last_tracked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['supplier_id', 'external_order_id']);
            $table->index('order_id');
            $table->index('status');
        });
        DB::statement("ALTER TABLE supplier_orders ADD CONSTRAINT supplier_orders_status_check CHECK (status IN (
            'procurement_pending','submitting','unknown','manual','purchased','supplier_processing','shipped','delivered',
            'exception','cancel_requested','cancelled'))");
        DB::statement("ALTER TABLE supplier_orders ADD CONSTRAINT supplier_orders_mode_check CHECK (procurement_mode IN ('automated','manual'))");
        // One live supplier order per supplier per customer order (cancelled ones may coexist after a source switch).
        DB::statement("CREATE UNIQUE INDEX uq_supplier_orders_live ON supplier_orders(order_id, supplier_id) WHERE status <> 'cancelled'");

        Schema::create('supplier_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_order_id')->constrained('supplier_orders');
            $table->foreignUuid('order_item_id')->constrained('order_items');
            $table->foreignUuid('supplier_product_id')->constrained('supplier_products');
            $table->string('external_product_id', 255);
            $table->string('external_variant_id', 255);
            $table->integer('purchase_quantity');
            $table->decimal('purchase_cost', 14, 4);
            $table->decimal('shipping_cost', 14, 4)->default(0);
            $table->char('currency', 3);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['supplier_order_id', 'order_item_id']);
        });
        DB::statement('ALTER TABLE supplier_order_items ADD CONSTRAINT soi_qty_check CHECK (purchase_quantity > 0)');

        Schema::create('procurement_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_order_id')->unique()->constrained('supplier_orders');
            $table->string('status', 40);
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->jsonb('last_error')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('locked_by', 100)->nullable();
            $table->foreignUuid('assigned_to')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->index(['status', 'next_attempt_at'], 'idx_procurement_tasks_due');
        });
        DB::statement("ALTER TABLE procurement_tasks ADD CONSTRAINT procurement_tasks_status_check CHECK (status IN (
            'queued','in_progress','waiting_retry','reconciling','manual','succeeded','exception','cancelled'))");

        Schema::create('procurement_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('procurement_task_id')->constrained('procurement_tasks');
            $table->integer('attempt_number');
            $table->string('action', 30);
            $table->string('outcome', 30);
            $table->string('error_class', 30)->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->foreignUuid('performed_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('procurement_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_attempts');
        Schema::dropIfExists('procurement_tasks');
        Schema::dropIfExists('supplier_order_items');
        Schema::dropIfExists('supplier_orders');
        DB::statement('DROP SEQUENCE IF EXISTS supplier_order_number_seq');
    }
};
