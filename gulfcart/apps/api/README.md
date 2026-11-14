# GulfCart API

Laravel 13 modular monolith. Read `../../AGENTS.md` and `../../docs/TECHNICAL_SPEC.md` first.

## Local development (without Docker)

Requires PHP 8.3+ (8.4 in Docker/CI), PostgreSQL, Redis.

```bash
composer install
cp .env.example .env    # set DB_HOST/REDIS_HOST to 127.0.0.1
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve                 # http://127.0.0.1:8000/api/v1
php artisan queue:work redis      # worker
php artisan schedule:work         # scheduler (outbox dispatch, pruning)
```

## Tests

Tests use the `gulfcart_test` PostgreSQL database and Redis on 127.0.0.1 (see `phpunit.xml`).

```bash
php artisan test
vendor/bin/pint --test
```

## Layout

- `app/Modules/{Identity,Countries,Audit}`: implemented in Milestone 1; other modules hold a README with their scope
- `app/Shared`: API envelope, exceptions, request ID, locale, idempotency, outbox, money, health
- `routes/api.php`: `/api/v1` routes; module routes live in `app/Modules/<Module>/Http/routes.php`

## Useful commands

| Command | Purpose |
|---|---|
| `php artisan app:health --ready` | Readiness check (used by Docker) |
| `php artisan app:queue-probe` | Confirms a worker is consuming the queue |
| `php artisan outbox:dispatch` | Publishes pending outbox events (scheduled every minute) |
