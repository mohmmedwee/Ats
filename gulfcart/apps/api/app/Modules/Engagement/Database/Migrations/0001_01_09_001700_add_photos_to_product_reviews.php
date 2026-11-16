<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->jsonb('photos')->default('[]'); // storage paths, shown once the review is approved
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', fn (Blueprint $table) => $table->dropColumn('photos'));
    }
};
