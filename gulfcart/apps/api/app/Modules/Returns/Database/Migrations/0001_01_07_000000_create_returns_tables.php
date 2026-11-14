<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS rma_number_seq START 10001');

        Schema::create('returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('rma_number', 50)->unique();
            $table->string('status', 30);
            $table->string('reason_code', 50);
            $table->text('customer_note')->nullable();
            $table->text('staff_note')->nullable();
            $table->decimal('refund_amount', 14, 2)->nullable();
            $table->char('currency', 3);
            $table->uuid('refund_id')->nullable();
            $table->foreignUuid('handled_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->index('order_id');
            $table->index('status');
        });
        DB::statement("ALTER TABLE returns ADD CONSTRAINT returns_status_check CHECK (status IN ('requested','approved','rejected','received','refunded','cancelled'))");

        Schema::create('return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->constrained('order_items');
            $table->integer('quantity');
            $table->string('reason_code', 50);
            $table->string('condition', 30)->nullable();
            $table->decimal('unit_refund', 14, 2);
            $table->unique(['return_id', 'order_item_id']);
        });
        DB::statement('ALTER TABLE return_items ADD CONSTRAINT return_items_qty_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT fk_refunds_return FOREIGN KEY (return_id) REFERENCES returns(id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS fk_refunds_return');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');
        DB::statement('DROP SEQUENCE IF EXISTS rma_number_seq');
    }
};
