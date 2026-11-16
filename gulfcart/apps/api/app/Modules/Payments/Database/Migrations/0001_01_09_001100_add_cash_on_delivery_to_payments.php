<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Cash on delivery: SMS confirmation code (hashed) and failed attempts.
            $table->string('confirmation_code_hash', 255)->nullable();
            $table->smallInteger('confirmation_attempts')->default(0);
        });
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_status_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('created','requires_action','authorized','captured','failed','cancelled','pending_collection','refused'))");

        // Customers who refused a cash-on-delivery parcel lose the option after the configured count.
        Schema::create('cod_refusals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('order_id')->unique();
            $table->string('note', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_refusals');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_status_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('created','requires_action','authorized','captured','failed','cancelled'))");
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['confirmation_code_hash', 'confirmation_attempts']));
    }
};
