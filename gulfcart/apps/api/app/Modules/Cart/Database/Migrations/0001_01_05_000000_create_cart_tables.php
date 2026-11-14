<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('guest_token', 64)->nullable()->unique();
            $table->foreignUuid('country_id')->constrained('countries');
            $table->char('currency', 3);
            $table->string('status', 30)->default('active');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_status_check CHECK (status IN ('active','converted','abandoned','merged'))");
        DB::statement('ALTER TABLE carts ADD CONSTRAINT carts_owner_check CHECK (user_id IS NOT NULL OR guest_token IS NOT NULL)');
        DB::statement("CREATE UNIQUE INDEX uq_active_cart_user ON carts(user_id, country_id) WHERE status = 'active' AND user_id IS NOT NULL");

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants');
            $table->integer('quantity');
            $table->decimal('price_at_add', 14, 2)->nullable();
            $table->timestampsTz();
            $table->unique(['cart_id', 'variant_id']);
        });
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_qty_check CHECK (quantity > 0 AND quantity <= 20)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
