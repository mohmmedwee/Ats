# System Context (C4 level 1)

```mermaid
flowchart LR
    C[Customer<br/>UAE / KSA] -->|browses, buys, tracks| GC[GulfCart platform]
    S[Staff<br/>ops, catalog, finance, support] -->|manages| GC
    GC -->|authorized purchase APIs / manual orders| SUP[Authorized suppliers]
    GC -->|payment intents, refunds, webhooks| PAY[Payment provider]
    GC -->|labels, tracking| CAR[Couriers / consolidators]
    GC -->|email, SMS, WhatsApp| MSG[Messaging providers]
    GC -->|rates| FX[Exchange-rate provider]
    SUP -->|ships directly| C
    CAR -->|delivers| C
```

| External system | Integration | Notes |
|---|---|---|
| Authorized suppliers | `SupplierConnectorInterface` | Only after written authorization; mock + manual until then |
| Payment provider | Hosted payment + webhooks | Must support AED, SAR, mada, Apple Pay (decision D2) |
| Couriers | Carrier adapters (Sprint 4) | Importer of Record arrangement required |
| Messaging | Notification channels | Templates in ar and en |
| FX provider | Scheduled fetch | Stale-rate alerting |
