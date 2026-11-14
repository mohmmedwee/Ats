# ADR-002: Laravel 13 backend

**Status:** Accepted

**Context.** Need fast delivery of auth, queues, scheduling, validation, migrations and
testing with a mature ecosystem.

**Decision.** Laravel 13 on PHP 8.4+, Sanctum for auth, `spatie/laravel-permission` for
RBAC, Pest for tests, Pint and Larastan for code quality, `brick/money` for money.

**Consequences.** Batteries included; queue and scheduler share the API image.
Long-running CPU work is not PHP's strength, but none is expected.
