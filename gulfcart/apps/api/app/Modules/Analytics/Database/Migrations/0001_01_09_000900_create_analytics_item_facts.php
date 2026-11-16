<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-line reporting view for profit by product (read-only; Analytics reporting exception).
 * Amounts are in the order currency; cost is converted with the rate locked at sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics_item_facts AS
            SELECT
                oi.id AS order_item_id,
                o.id AS order_id,
                c.code AS country,
                o.currency,
                o.paid_at,
                oi.product_id,
                oi.product_snapshot->'title'->>'en' AS title_en,
                oi.product_snapshot->'title'->>'ar' AS title_ar,
                oi.product_snapshot->>'image_url' AS image_url,
                oi.quantity,
                oi.refunded_quantity,
                oi.line_total,
                oi.line_discount,
                oi.vat_rate,
                (oi.unit_supplier_cost + oi.unit_supplier_shipping) * oi.exchange_rate AS unit_landed_cost
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN countries c ON c.id = o.country_id
            WHERE o.paid_at IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS analytics_item_facts');
    }
};
