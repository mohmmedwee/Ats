<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', fn (Blueprint $t) => $t->timestampTz('reminded_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('carts', fn (Blueprint $t) => $t->dropColumn('reminded_at'));
    }
};
