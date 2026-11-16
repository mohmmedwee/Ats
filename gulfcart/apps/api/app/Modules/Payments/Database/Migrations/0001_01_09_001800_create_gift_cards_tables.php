<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gift cards are stored value: they pay for (part of) an order, and refunds go back onto them.
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 24)->unique();
            $table->char('currency', 3);
            $table->decimal('initial_amount', 14, 2);
            $table->decimal('balance', 14, 2);
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE gift_cards ADD CONSTRAINT gift_cards_balance_check CHECK (balance >= 0 AND balance <= initial_amount AND initial_amount > 0)');

        Schema::create('gift_card_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('gift_card_id')->constrained('gift_cards');
            $table->string('type', 20);
            $table->decimal('amount', 14, 2); // signed: redeem negative, refund positive
            $table->uuid('payment_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE gift_card_transactions ADD CONSTRAINT gift_card_tx_type_check CHECK (type IN ('issue','redeem','refund','adjust'))");
        DB::statement("CREATE UNIQUE INDEX gift_card_tx_refund_once ON gift_card_transactions (reference) WHERE type = 'refund'");
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
    }
};
