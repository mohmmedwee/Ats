<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Brands become records (logo, description, whether we are authorized to sell them); categories get an
 * image and can require an authorized brand (electronics, cosmetics); products get specifications and
 * warranty text. The free-text products.brand stays, kept in sync, for search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->text('logo_path')->nullable();
            $table->boolean('is_authorized')->default(false);
            $table->string('authorization_note', 255)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('brand_id')->nullable()->constrained('brands');
            $table->jsonb('specifications')->nullable();
            $table->jsonb('warranty')->nullable();
            $table->index('brand_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->text('image_path')->nullable();
            $table->boolean('requires_authorized_brand')->default(false);
        });

        // Existing free-text brands become brand records.
        foreach (DB::table('products')->whereNotNull('brand')->where('brand', '<>', '')->distinct()->pluck('brand') as $name) {
            $slug = Str::slug($name) ?: 'brand-'.Str::lower(Str::random(6));
            $id = DB::table('brands')->where('slug', $slug)->value('id');
            if ($id === null) {
                $id = (string) Str::uuid7();
                DB::table('brands')->insert(['id' => $id, 'slug' => $slug, 'name' => json_encode(['en' => $name, 'ar' => $name]), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('products')->where('brand', $name)->update(['brand_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn(['image_path', 'requires_authorized_brand']));
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('brand_id');
            $t->dropColumn(['specifications', 'warranty']);
        });
        Schema::dropIfExists('brands');
    }
};
