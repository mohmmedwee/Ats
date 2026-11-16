<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Tell me when it is back" and "tell me when the price drops", per customer, product and country.
        Schema::create('product_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->string('type', 20);
            $table->char('country_code', 2);
            $table->decimal('price_at_subscribe', 14, 2)->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();
            $table->index(['notified_at', 'type']);
        });
        DB::statement("ALTER TABLE product_alerts ADD CONSTRAINT product_alerts_type_check CHECK (type IN ('back_in_stock','price_drop'))");
        // One open alert per customer, product, option and type.
        DB::statement('CREATE UNIQUE INDEX product_alerts_open_unique ON product_alerts (user_id, product_id, COALESCE(variant_id, \'00000000-0000-0000-0000-000000000000\'), type) WHERE notified_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_alerts');
    }
};
