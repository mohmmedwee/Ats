<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('country_id')->constrained('countries');
            // PII encrypted at rest (Laravel encrypted casts): stored as text.
            $table->text('full_name');
            $table->text('phone');
            $table->text('address_line_1');
            $table->text('address_line_2')->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('postal_code', 30)->nullable();
            $table->text('national_address_code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->softDeletesTz();
            $table->timestampsTz();
            $table->index('user_id');
        });
        DB::statement('CREATE UNIQUE INDEX uq_default_address ON customer_addresses(user_id, country_id) WHERE is_default AND deleted_at IS NULL');

        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained('carts');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('country_id')->constrained('countries');
            $table->foreignUuid('address_id')->constrained('customer_addresses');
            $table->foreignUuid('shipping_method_id')->constrained('shipping_methods');
            $table->char('currency', 3);
            $table->jsonb('quote');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('shipping_total', 14, 2);
            $table->decimal('tax_total', 14, 2);
            $table->decimal('customs_total', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2);
            $table->string('status', 30)->default('open');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE checkout_sessions ADD CONSTRAINT checkout_sessions_status_check CHECK (status IN ('open','completed','expired','cancelled'))");
        // Prices are VAT-inclusive: tax_total is the VAT contained in the total, not added to it.
        DB::statement('ALTER TABLE checkout_sessions ADD CONSTRAINT checkout_sessions_total_check CHECK (grand_total = subtotal + shipping_total + customs_total - discount_total)');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
        Schema::dropIfExists('customer_addresses');
    }
};
