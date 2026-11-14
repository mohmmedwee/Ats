<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id')->constrained('countries');
            $table->string('code', 50);
            $table->jsonb('name');
            $table->string('carrier', 100)->nullable();
            $table->string('pricing_model', 30);
            $table->jsonb('config')->default('{}');
            $table->decimal('free_shipping_threshold', 14, 2)->nullable();
            $table->smallInteger('extra_min_days')->default(0);
            $table->smallInteger('extra_max_days')->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['country_id', 'code']);
        });
        DB::statement("ALTER TABLE shipping_methods ADD CONSTRAINT shipping_methods_model_check CHECK (pricing_model IN ('flat','weight_band'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
