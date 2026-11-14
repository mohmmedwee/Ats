-- Run after `php artisan migrate --force` in every release (as gulfcart_owner).
REVOKE UPDATE, DELETE ON audit_logs, invoices FROM gulfcart_app;
REVOKE DELETE ON payment_webhook_events, refunds, payments, order_items, orders FROM gulfcart_app;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO gulfcart_reporting;
