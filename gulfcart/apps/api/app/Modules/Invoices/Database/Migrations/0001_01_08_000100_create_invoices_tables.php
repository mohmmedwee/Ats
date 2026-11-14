<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gapless numbering per country, document type and year (tax-invoice requirement).
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->char('country', 2);
            $table->string('type', 20);
            $table->smallInteger('year');
            $table->integer('last_number')->default(0);
            $table->primary(['country', 'type', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 40)->unique();
            $table->string('type', 20);
            $table->foreignUuid('order_id')->constrained('orders');
            $table->uuid('refund_id')->nullable()->unique();
            $table->uuid('original_invoice_id')->nullable();
            $table->char('country', 2);
            $table->char('currency', 3);
            $table->jsonb('seller');
            $table->jsonb('buyer');
            $table->jsonb('lines');
            $table->decimal('net_total', 14, 2);
            $table->decimal('vat_total', 14, 2);
            $table->decimal('gross_total', 14, 2);
            $table->text('qr_payload')->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('order_id');
        });
        Schema::table('invoices', fn (Blueprint $table) => $table->foreign('original_invoice_id')->references('id')->on('invoices'));
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (type IN ('invoice','credit_note'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_totals_check CHECK (gross_total = net_total + vat_total)');
        DB::statement("CREATE UNIQUE INDEX uq_invoice_per_order ON invoices(order_id) WHERE type = 'invoice'");
        // Issued tax documents are immutable.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoices_immutable() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'invoices are immutable; issue a credit note instead'; END $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_invoices_immutable BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION invoices_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_invoices_immutable ON invoices; DROP FUNCTION IF EXISTS invoices_immutable();');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
