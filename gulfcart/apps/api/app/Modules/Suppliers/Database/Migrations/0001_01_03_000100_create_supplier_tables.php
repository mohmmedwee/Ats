<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('connector_type', 50);
            $table->char('base_currency', 3);
            $table->text('api_base_url')->nullable();
            $table->text('credentials_ref')->nullable();
            $table->string('commercial_authorization_status', 30)->default('pending');
            $table->boolean('direct_shipping_enabled')->default(false);
            $table->boolean('is_active')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->softDeletesTz();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_auth_check CHECK (commercial_authorization_status IN ('pending','approved','suspended','revoked'))");
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_active_requires_approval CHECK (NOT is_active OR commercial_authorization_status = 'approved')");

        Schema::create('supplier_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->string('agreement_reference', 255);
            $table->boolean('allows_resale');
            $table->boolean('allows_api_purchasing');
            $table->boolean('allows_direct_shipping');
            $table->boolean('allows_image_use');
            $table->text('document_path')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->index('supplier_id');
        });
        DB::statement('ALTER TABLE supplier_authorizations ADD COLUMN countries CHAR(2)[] NOT NULL');

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->foreignUuid('product_id')->constrained('products');
            $table->string('external_product_id', 255);
            $table->string('external_sku', 255)->nullable();
            $table->text('source_url')->nullable();
            $table->decimal('cost_price', 14, 4);
            $table->char('cost_currency', 3);
            $table->string('stock_status', 30);
            $table->smallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->jsonb('supplier_data')->default('{}');
            $table->timestampTz('last_synced_at')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();
            $table->unique(['supplier_id', 'external_product_id']);
            $table->index(['product_id', 'priority']);
        });
        DB::statement("ALTER TABLE supplier_products ADD CONSTRAINT supplier_products_stock_check CHECK (stock_status IN ('in_stock','low_stock','out_of_stock','unknown'))");
        DB::statement('ALTER TABLE supplier_products ADD CONSTRAINT supplier_products_cost_check CHECK (cost_price >= 0)');

        Schema::create('supplier_variant_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_product_id')->constrained('supplier_products');
            $table->foreignUuid('variant_id')->constrained('product_variants');
            $table->string('external_variant_id', 255);
            $table->decimal('cost_price', 14, 4)->nullable();
            $table->string('stock_status', 30)->nullable();
            $table->timestampsTz();
            $table->unique(['supplier_product_id', 'variant_id']);
            $table->unique(['supplier_product_id', 'external_variant_id']);
            $table->index('variant_id');
        });
        DB::statement("ALTER TABLE supplier_variant_mappings ADD CONSTRAINT svm_stock_check CHECK (stock_status IS NULL OR stock_status IN ('in_stock','low_stock','out_of_stock','unknown'))");

        Schema::create('product_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users');
            $table->text('file_path');
            $table->string('status', 20)->default('queued');
            $table->integer('total_rows')->default(0);
            $table->integer('created_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->jsonb('errors')->default('[]');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_status_check CHECK (status IN ('queued','processing','completed','failed'))");

        // Simulated remote order store for MockSupplierConnector (testing and staging only).
        Schema::create('mock_supplier_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->string('idempotency_key', 255);
            $table->string('external_order_id', 100)->unique();
            $table->string('status', 30);
            $table->jsonb('items');
            $table->decimal('total_cost', 14, 4);
            $table->char('currency', 3);
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->jsonb('events')->default('[]');
            $table->timestampsTz();
            $table->unique(['supplier_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        foreach (['mock_supplier_orders', 'product_imports', 'supplier_variant_mappings', 'supplier_products', 'supplier_authorizations', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
