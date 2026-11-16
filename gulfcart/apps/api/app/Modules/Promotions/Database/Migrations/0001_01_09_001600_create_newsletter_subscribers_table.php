<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email', 255);
            $table->string('locale', 5)->default('ar');
            $table->char('country_code', 2)->nullable();
            $table->string('source', 30)->default('footer');
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestampTz('subscribed_at');
            $table->timestampTz('unsubscribed_at')->nullable();
        });
        DB::statement('ALTER TABLE newsletter_subscribers ALTER COLUMN email TYPE citext');
        DB::statement('CREATE UNIQUE INDEX newsletter_subscribers_email_unique ON newsletter_subscribers (email)');
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
