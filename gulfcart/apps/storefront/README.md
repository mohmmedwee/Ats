# GulfCart Storefront

Nuxt 4 storefront and admin layout. Arabic (RTL) and English, AED/SAR country selection,
Sanctum SPA cookie authentication.

## Local development (without Docker)

```bash
npm install
npm run dev          # http://localhost:3000, proxies /api and /sanctum to http://127.0.0.1:8000
```

Run the API alongside it: `cd ../api && php artisan serve`.

## Checks

```bash
npm run lint
npm run typecheck
npm test
npm run build
```

## Structure

- `app/pages`: `/`, `/login`, `/register`, `/mfa`, `/account`, `/admin`; Arabic under `/ar/...`
- `app/stores/auth.ts`: current user, login, register, MFA challenge, logout
- `app/composables/useApi.ts`: API client (credentials, XSRF, `Accept-Language`, `X-Country`)
- `i18n/locales/{en,ar}.json`: UI strings; keys must match (enforced by a test)
