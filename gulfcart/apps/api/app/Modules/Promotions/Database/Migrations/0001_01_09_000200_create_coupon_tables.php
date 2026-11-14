<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('description', 255)->nullable();
            $table->string('type', 20);
            $table->decimal('value', 12, 2)->default(0);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->jsonb('countries')->default('[]');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('per_customer_limit')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (type IN ('percent','fixed','free_shipping'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_value_check CHECK (value >= 0 AND (type <> 'percent' OR value <= 90))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_code_upper CHECK (code = upper(code))');

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->constrained('coupons');
            $table->uuid('user_id');
            $table->uuid('order_id')->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->index(['coupon_id', 'status']);
            $table->index(['coupon_id', 'user_id']);
        });
        DB::statement("ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_status_check CHECK (status IN ('active','released'))");

        // Discount allocation stored with the order (VAT-inclusive, per line).
        Schema::table('orders', function (Blueprint $table) {
            $table->string('coupon_code', 40)->nullable();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('line_discount', 14, 2)->default(0);
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_discount_check CHECK (line_discount >= 0 AND line_discount <= line_total)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_items_immutable() RETURNS trigger AS $$
            BEGIN
                IF NEW.unit_gross_price <> OLD.unit_gross_price OR NEW.line_total <> OLD.line_total
                   OR NEW.unit_supplier_cost <> OLD.unit_supplier_cost OR NEW.quantity <> OLD.quantity
                   OR NEW.exchange_rate <> OLD.exchange_rate OR NEW.line_discount <> OLD.line_discount THEN
                    RAISE EXCEPTION 'order_items financial snapshot is immutable';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_discount_check');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('line_discount'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('coupon_code'));
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
