<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Search that forgives how people type: Arabic letter variants (أ إ آ ٱ → ا, ى → ي, ة → ه, ؤ → و, ئ → ي),
 * diacritics and tatweel removed, Arabic-Indic digits → 0-9, lower case; plus trigram similarity for typos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS pg_trgm;

            CREATE OR REPLACE FUNCTION gc_search_normalize(input text) RETURNS text AS $$
                SELECT translate(
                    regexp_replace(lower(coalesce(input, '')), '[ً-ٰٟـ]', '', 'g'),
                    'أإآٱىةؤئ٠١٢٣٤٥٦٧٨٩',
                    'اااايهوي0123456789'
                )
            $$ LANGUAGE sql IMMUTABLE PARALLEL SAFE;

            ALTER TABLE products ADD COLUMN search_text text GENERATED ALWAYS AS (
                gc_search_normalize(coalesce(title->>'en', '') || ' ' || coalesce(title->>'ar', '') || ' ' || coalesce(brand, ''))
            ) STORED;

            CREATE INDEX products_search_trgm ON products USING gin (search_text gin_trgm_ops);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS products_search_trgm; ALTER TABLE products DROP COLUMN IF EXISTS search_text; DROP FUNCTION IF EXISTS gc_search_normalize(text);');
    }
};
