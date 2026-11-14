<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->jsonb('name');
            $table->string('slug', 180)->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletesTz();
            $table->timestampsTz();
        });
        Schema::table('categories', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('categories'));

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->nullable()->constrained('categories');
            $table->jsonb('title');
            $table->string('slug', 255)->unique();
            $table->jsonb('description')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('brand', 150)->nullable();
            $table->jsonb('attributes')->default('{}');
            $table->integer('weight_grams')->nullable();
            $table->string('hs_code', 20)->nullable();
            $table->char('country_of_origin', 2)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();
            $table->index('category_id');
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft','pending_review','published','unpublished','archived'))");
        DB::statement('CREATE INDEX idx_products_status ON products(status) WHERE deleted_at IS NULL');
        DB::statement("CREATE INDEX idx_products_title_trgm ON products USING gin ((coalesce(title->>'en','') || ' ' || coalesce(title->>'ar','') || ' ' || coalesce(brand,'')) gin_trgm_ops)");

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products');
            $table->string('sku', 100)->unique();
            $table->jsonb('attributes')->default('{}');
            $table->integer('weight_grams')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index('product_id');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('variant_id')->nullable()->constrained('product_variants');
            $table->text('storage_path')->nullable();
            $table->text('source_url')->nullable();
            $table->string('rights_status', 30)->default('pending');
            $table->string('rights_basis', 100)->nullable();
            $table->jsonb('alt_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->index('product_id');
        });
        DB::statement("ALTER TABLE product_images ADD CONSTRAINT product_images_rights_check CHECK (rights_status IN ('pending','approved','rejected'))");
        DB::statement('ALTER TABLE product_images ADD CONSTRAINT product_images_source_check CHECK (storage_path IS NOT NULL OR source_url IS NOT NULL)');

        // Per-country availability plus the denormalised listing state used by the storefront.
        Schema::create('product_country_availability', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('country_id')->constrained('countries');
            $table->boolean('is_available')->default(true);
            $table->smallInteger('estimated_delivery_min_days')->nullable();
            $table->smallInteger('estimated_delivery_max_days')->nullable();
            $table->string('restriction_reason', 255)->nullable();
            $table->boolean('is_sellable')->default(false);
            $table->string('unsellable_reason', 60)->nullable();
            $table->decimal('from_price', 14, 2)->nullable();
            $table->char('from_price_currency', 3)->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['product_id', 'country_id']);
            $table->index(['country_id', 'is_sellable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_country_availability');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
