<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('code', 2)->unique();
            $table->jsonb('name');
            $table->char('currency_code', 3);
            $table->string('default_locale', 10);
            $table->string('timezone', 64);
            $table->jsonb('settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE countries ADD COLUMN supported_locales VARCHAR(10)[] NOT NULL DEFAULT \'{}\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
