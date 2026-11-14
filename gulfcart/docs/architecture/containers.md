# Containers (C4 level 2)

```mermaid
flowchart TB
    subgraph Edge
      CDN[CDN / WAF]
      NGINX[Nginx]
    end
    subgraph App
      NUXT[Nuxt SSR<br/>storefront + /admin]
      API[Laravel API<br/>PHP-FPM, modular monolith]
      WRK[Queue workers<br/>procurement, payments, outbox, notifications]
      SCH[Scheduler<br/>FX, reconciliation, lock cleanup]
    end
    subgraph Data
      PG[(PostgreSQL 17<br/>source of truth)]
      RD[(Redis 7<br/>queues, cache, sessions)]
      S3[(S3 storage<br/>images, imports, documents)]
    end
    CDN --> NGINX --> NUXT
    NGINX --> API
    NUXT -->|REST /api/v1| API
    API --> PG
    API --> RD
    API --> S3
    WRK --> PG
    WRK --> RD
    WRK --> S3
    SCH --> RD
```

| Container | Technology | Responsibility | Scales by |
|---|---|---|---|
| Nginx | nginx 1.27 | TLS termination (behind CDN), routing `/api` vs storefront | 1–2 instances |
| Nuxt | Nuxt (ADR-003), Node 22 | SSR storefront, admin SPA layout, i18n/RTL | Horizontal |
| API | Laravel 13, PHP 8.4 FPM | All HTTP endpoints, all modules | Horizontal |
| Workers | Same image as API | Queue jobs per named queue | Per queue |
| Scheduler | Same image as API | Cron-like tasks; exactly one instance | Single |
| PostgreSQL | 17 | Transactional data, outbox | Vertical + read replica |
| Redis | 7 | Queues, cache, sessions, locks; never financial truth | Vertical |
| S3 | S3-compatible | Images (public via CDN), private documents | Managed |
