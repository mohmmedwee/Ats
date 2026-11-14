<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reporting read model. Analytics is the one module allowed to read other modules' tables,
 * and only through these read-only views (docs/TECHNICAL_SPEC.md §3, reporting exception).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics_order_facts AS
            SELECT
                o.id AS order_id,
                o.order_number,
                c.code AS country,
                o.currency,
                o.status,
                o.refund_state,
                o.placed_at,
                o.paid_at,
                o.grand_total,
                o.tax_total,
                o.customs_total,
                o.shipping_total,
                o.paid_total,
                o.refunded_total,
                COALESCE(SUM(oi.quantity * (oi.unit_supplier_cost + oi.unit_supplier_shipping) * oi.exchange_rate), 0)::numeric(14,2) AS landed_cost,
                COALESCE(SUM(oi.quantity), 0) AS units
            FROM orders o
            JOIN countries c ON c.id = o.country_id
            LEFT JOIN order_items oi ON oi.order_id = o.id
            GROUP BY o.id, c.code
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS analytics_order_facts');
    }
};
