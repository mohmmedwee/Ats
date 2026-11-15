# Deploying GulfCart to a server

One server is enough to launch: about 4 vCPU, 8 GB RAM, 80 GB SSD, Ubuntu 24.04, in a Gulf region
(for example AWS me-central-1 UAE, Azure UAE North, or a local provider). Everything runs in Docker;
HTTPS certificates are automatic.

## 1. DNS

Point both names at the server's IP (A records):

| Host | Purpose |
|---|---|
| `gulfcart.com` (and `www`) | Store and admin panel |
| `api.gulfcart.com` | Same backend, used by payment webhooks and product feeds |

## 2. Server

```bash
curl -fsSL https://get.docker.com | sh
git clone <your repository> gulfcart && cd gulfcart/gulfcart
cp deploy/.env.example deploy/.env           # domains + database/redis passwords
cp deploy/api.env.example deploy/api.env     # all API settings and keys
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'   # → APP_KEY
```

Fill `deploy/api.env`: email SMTP, Tap, CJ, SMS, WhatsApp, pixels. Every key is explained in
[`../apps/api/.env.example`](../apps/api/.env.example).

## 3. First deploy

```bash
./deploy/deploy.sh
```

This builds the images, runs migrations and seeders (countries, VAT, shipping, roles, policy pages), and
starts everything. Then create your owner account (the development admin is never created in production):

```bash
C="docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env"
$C exec api php artisan users:create-owner you@your-company.com "Your Name"
```

Open the password link from the email, sign in at `https://gulfcart.com/login?mode=email`, set up
two-step verification, then invite your team from **Admin → Team**.

## 4. Updates

```bash
git pull && ./deploy/deploy.sh
```

Migrations are backward compatible, so the store keeps running during updates.

## 5. Backups

- **Database:** the `backup` service dumps the database every day and keeps 14 days in the `backups`
  volume. **Copy them off the server** daily, for example with `rclone` to S3 or Backblaze.
- **Restore test:** run `infrastructure/scripts/restore-drill.sh` once a month.
- **Images:** they live in the `storage_public` volume. Back it up too, or set `CATALOG_DISK=s3`.

## 6. After going live

- **Uptime:** watch `https://api.gulfcart.com/api/v1/health/ready` with a free monitor such as
  UptimeRobot or Better Stack.
- **Daily checks:** see [`OPERATIONS.md`](OPERATIONS.md): procurement exceptions, refunds waiting,
  stale exchange rates.
- **Search engines:** submit `https://gulfcart.com/sitemap.xml` to Google Search Console.
- **Ads catalogs:** use these product feeds in Meta Commerce Manager, Google Merchant Center and
  TikTok:
  - `https://api.gulfcart.com/api/v1/feeds/products.xml?country=AE&lang=ar`
  - add the `en` and `SA` variants as needed.
