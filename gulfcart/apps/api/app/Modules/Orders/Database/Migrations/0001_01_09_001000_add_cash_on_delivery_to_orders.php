<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // null = paid online; 'cod' = cash on delivery
            $table->string('payment_method', 20)->nullable()->after('currency');
        });
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (
            'pending_payment','payment_failed','expired','paid','cod_confirmed','processing','partially_shipped','shipped','delivered','completed','cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (
            'pending_payment','payment_failed','expired','paid','processing','partially_shipped','shipped','delivered','completed','cancelled'))");
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('payment_method'));
    }
};
