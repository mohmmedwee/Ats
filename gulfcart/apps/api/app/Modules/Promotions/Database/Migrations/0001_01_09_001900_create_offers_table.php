<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automatic quantity offers: buy X, get Y at a discount (100% = free). Applied at checkout, no code.
        Schema::create('offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->jsonb('name');
            $table->smallInteger('buy_qty');
            $table->smallInteger('get_qty');
            $table->decimal('get_percent', 5, 2);
            $table->string('scope', 20);
            $table->uuid('category_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->jsonb('countries')->default('[]');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_qty_check CHECK (buy_qty BETWEEN 1 AND 20 AND get_qty BETWEEN 1 AND 20 AND get_percent > 0 AND get_percent <= 100)');
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_scope_check CHECK ((scope = 'all') OR (scope = 'category' AND category_id IS NOT NULL) OR (scope = 'product' AND product_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
