<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->jsonb('name');
            $table->decimal('percent_off', 5, 2);
            $table->string('scope', 20);
            $table->uuid('category_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->jsonb('countries')->default('[]');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE flash_sales ADD CONSTRAINT flash_sales_percent_check CHECK (percent_off > 0 AND percent_off <= 90)');
        DB::statement("ALTER TABLE flash_sales ADD CONSTRAINT flash_sales_scope_check CHECK ((scope = 'all') OR (scope = 'category' AND category_id IS NOT NULL) OR (scope = 'product' AND product_id IS NOT NULL))");
        DB::statement('ALTER TABLE flash_sales ADD CONSTRAINT flash_sales_window_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sales');
    }
};
