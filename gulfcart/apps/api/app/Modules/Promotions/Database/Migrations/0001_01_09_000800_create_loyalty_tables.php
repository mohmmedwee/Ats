<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal coupons (points converted to money off, referral welcome gifts).
        Schema::table('coupons', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('code');
            $table->string('source', 20)->nullable()->after('user_id');
            $table->index('user_id');
        });

        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->integer('balance')->default(0);
            $table->string('referral_code', 16)->unique();
            $table->uuid('referred_by')->nullable();
            $table->timestampTz('referral_rewarded_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE loyalty_accounts ADD CONSTRAINT loyalty_accounts_balance_check CHECK (balance >= 0)');

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type', 20);
            $table->integer('points');
            $table->uuid('order_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
        DB::statement("ALTER TABLE loyalty_transactions ADD CONSTRAINT loyalty_transactions_type_check CHECK (type IN ('earn','reversal','redeem','referral_bonus','adjustment'))");
        // One earning and one referral bonus per order, whatever retries happen.
        DB::statement("CREATE UNIQUE INDEX loyalty_tx_once_per_order ON loyalty_transactions (order_id, type) WHERE order_id IS NOT NULL AND type IN ('earn','referral_bonus')");
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'source']);
        });
    }
};
