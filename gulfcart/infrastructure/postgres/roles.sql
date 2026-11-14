-- Production database roles (docs/SECURITY_SPEC.md, DEPLOYMENT.md §6).
-- Run once as a superuser after creating the database. Replace passwords via your secret manager.
--
--   gulfcart_owner     owns the schema; used ONLY by `php artisan migrate` in the release step
--   gulfcart_app       API, workers, scheduler: data access, no DDL
--   gulfcart_reporting read-only analytics/BI
--
-- audit_logs, invoices and payment_webhook_events are append-only for the app role.

CREATE ROLE gulfcart_owner LOGIN PASSWORD 'change-me-owner';
CREATE ROLE gulfcart_app LOGIN PASSWORD 'change-me-app';
CREATE ROLE gulfcart_reporting LOGIN PASSWORD 'change-me-reporting';

ALTER DATABASE gulfcart OWNER TO gulfcart_owner;
\connect gulfcart
ALTER SCHEMA public OWNER TO gulfcart_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO gulfcart_app, gulfcart_reporting;

-- Tables created later by migrations inherit these defaults.
ALTER DEFAULT PRIVILEGES FOR ROLE gulfcart_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO gulfcart_app;
ALTER DEFAULT PRIVILEGES FOR ROLE gulfcart_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO gulfcart_app;
ALTER DEFAULT PRIVILEGES FOR ROLE gulfcart_owner IN SCHEMA public
    GRANT SELECT ON TABLES TO gulfcart_reporting;

-- After each migration run (release step), re-apply the append-only restrictions:
--   REVOKE UPDATE, DELETE ON audit_logs, invoices FROM gulfcart_app;
--   REVOKE DELETE ON payment_webhook_events, refunds, payments, order_items FROM gulfcart_app;
-- (invoices and order_items also have immutability triggers.)
