<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('base_currency', 3);
            $table->char('target_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->string('source', 100);
            $table->timestampTz('effective_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['base_currency', 'target_currency', 'effective_at']);
            $table->index(['base_currency', 'target_currency', 'effective_at'], 'idx_exchange_rates_lookup');
        });
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_check CHECK (rate > 0)');

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 20);
            $table->foreignUuid('country_id')->nullable()->constrained('countries');
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers');
            $table->foreignUuid('category_id')->nullable()->constrained('categories');
            $table->foreignUuid('product_id')->nullable()->constrained('products');
            $table->string('rule_type', 20);
            $table->decimal('value', 12, 4);
            $table->decimal('min_margin_percent', 8, 4)->nullable();
            $table->decimal('max_selling_price', 14, 2)->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_scope_check CHECK (scope IN ('global','country','supplier','category','product'))");
        DB::statement("ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_type_check CHECK (rule_type IN ('margin_percent','markup_percent','fixed_markup'))");
        DB::statement("ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_scope_target_check CHECK (
            (scope = 'global') OR (scope = 'country' AND country_id IS NOT NULL) OR (scope = 'supplier' AND supplier_id IS NOT NULL)
            OR (scope = 'category' AND category_id IS NOT NULL) OR (scope = 'product' AND product_id IS NOT NULL))");

        Schema::create('product_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('variant_id')->constrained('product_variants');
            $table->foreignUuid('country_id')->constrained('countries');
            $table->foreignUuid('supplier_product_id')->constrained('supplier_products');
            $table->decimal('gross_price', 14, 2);
            $table->decimal('net_price', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('vat_rate', 8, 4);
            $table->char('currency', 3);
            $table->decimal('variable_cost', 14, 4);
            $table->foreignUuid('exchange_rate_id')->nullable()->constrained('exchange_rates');
            $table->jsonb('calculation');
            $table->timestampTz('valid_until')->nullable();
            $table->timestampsTz();
            $table->unique(['variant_id', 'country_id']);
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('exchange_rates');
    }
};
