<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional map pin: Gulf addresses are often hard to find from the text alone.
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_addresses', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
    }
};
