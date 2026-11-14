<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS order_number_seq START 10001');

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number', 50)->unique();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('checkout_session_id')->unique()->constrained('checkout_sessions');
            $table->foreignUuid('country_id')->constrained('countries');
            $table->foreignUuid('address_id')->nullable()->constrained('customer_addresses');
            $table->jsonb('shipping_address_snapshot');
            $table->jsonb('shipping_method_snapshot');
            $table->char('currency', 3);
            $table->string('locale', 10);
            $table->string('status', 40);
            $table->string('refund_state', 20)->default('none');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('customs_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->decimal('refunded_total', 14, 2)->default(0);
            $table->timestampTz('placed_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('payment_due_at');
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (
            'pending_payment','payment_failed','expired','paid','processing','partially_shipped','shipped','delivered','completed','cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_refund_state_check CHECK (refund_state IN ('none','partial','full'))");
        // VAT-inclusive prices: tax_total is contained in grand_total.
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_check CHECK (grand_total = subtotal + shipping_total + customs_total - discount_total)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_refund_check CHECK (refunded_total >= 0 AND refunded_total <= paid_total)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignUuid('product_id')->nullable()->constrained('products');
            $table->foreignUuid('variant_id')->nullable()->constrained('product_variants');
            $table->foreignUuid('supplier_product_id')->constrained('supplier_products');
            $table->uuid('supplier_id');
            $table->jsonb('product_snapshot');
            $table->integer('quantity');
            $table->decimal('unit_gross_price', 14, 2);
            $table->decimal('unit_net_price', 14, 2);
            $table->decimal('unit_tax', 14, 2);
            $table->decimal('vat_rate', 8, 4);
            $table->decimal('line_total', 14, 2);
            $table->decimal('unit_supplier_cost', 14, 4);
            $table->decimal('unit_supplier_shipping', 14, 4)->default(0);
            $table->char('supplier_currency', 3);
            $table->uuid('exchange_rate_id')->nullable();
            $table->decimal('exchange_rate', 18, 8);
            $table->jsonb('pricing_snapshot');
            $table->smallInteger('shipment_group');
            $table->string('fulfillment_status', 30)->default('pending');
            $table->integer('refunded_quantity')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->index('order_id');
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_check CHECK (quantity > 0 AND refunded_quantity >= 0 AND refunded_quantity <= quantity)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_fulfillment_check CHECK (fulfillment_status IN ('pending','purchased','shipped','delivered','cancelled','returned'))");
        // Financial snapshot columns of order items are immutable (ADR-009).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_items_immutable() RETURNS trigger AS $$
            BEGIN
                IF NEW.unit_gross_price <> OLD.unit_gross_price OR NEW.line_total <> OLD.line_total
                   OR NEW.unit_supplier_cost <> OLD.unit_supplier_cost OR NEW.quantity <> OLD.quantity
                   OR NEW.exchange_rate <> OLD.exchange_rate THEN
                    RAISE EXCEPTION 'order_items financial snapshot is immutable';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_order_items_immutable BEFORE UPDATE ON order_items
                FOR EACH ROW EXECUTE FUNCTION order_items_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_immutable ON order_items; DROP FUNCTION IF EXISTS order_items_immutable();');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
