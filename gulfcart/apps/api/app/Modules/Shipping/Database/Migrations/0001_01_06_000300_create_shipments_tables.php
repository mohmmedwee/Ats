<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignUuid('supplier_order_id')->constrained('supplier_orders');
            $table->smallInteger('shipment_group');
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 255)->nullable();
            $table->text('tracking_url')->nullable();
            $table->string('status', 40);
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->unique(['supplier_order_id', 'tracking_number']);
            $table->index('order_id');
            $table->index('tracking_number');
        });
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('pending','label_created','in_transit','customs','out_for_delivery','delivered','exception','returned'))");

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->foreignUuid('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->constrained('order_items');
            $table->integer('quantity');
            $table->primary(['shipment_id', 'order_item_id']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('event_code', 100);
            $table->text('description')->nullable();
            $table->string('event_location', 255)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['shipment_id', 'event_code', 'occurred_at']);
            $table->index(['shipment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
    }
};
