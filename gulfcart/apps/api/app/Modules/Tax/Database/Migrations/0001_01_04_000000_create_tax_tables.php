<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id')->constrained('countries');
            $table->string('tax_type', 30);
            $table->foreignUuid('category_id')->nullable()->constrained('categories');
            $table->decimal('rate_percent', 8, 4);
            $table->boolean('is_recoverable')->default(false);
            $table->boolean('applies_to_shipping')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();
            $table->index(['country_id', 'tax_type', 'effective_from']);
        });
        DB::statement("ALTER TABLE tax_rules ADD CONSTRAINT tax_rules_type_check CHECK (tax_type IN ('vat','import_vat'))");
        DB::statement('ALTER TABLE tax_rules ADD CONSTRAINT tax_rules_rate_check CHECK (rate_percent >= 0 AND rate_percent < 100)');

        Schema::create('customs_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id')->constrained('countries');
            $table->string('hs_code_prefix', 20)->nullable();
            $table->decimal('duty_percent', 8, 4);
            $table->string('valuation_basis', 10)->default('CIF');
            $table->decimal('de_minimis_amount', 14, 2)->nullable();
            $table->char('de_minimis_currency', 3)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();
            $table->index(['country_id', 'effective_from']);
        });
        DB::statement("ALTER TABLE customs_rules ADD CONSTRAINT customs_rules_basis_check CHECK (valuation_basis IN ('CIF','FOB'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_rules');
        Schema::dropIfExists('tax_rules');
    }
};
