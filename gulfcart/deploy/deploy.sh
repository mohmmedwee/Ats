#!/bin/sh
# Build and roll out the current checkout. Run from the repository root on the server.
set -eu
cd "$(dirname "$0")/.."
export RELEASE="$(git rev-parse --short HEAD)"
C="docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env"
$C build
$C up -d postgres redis
$C run --rm migrate
$C up -d --remove-orphans
$C exec -T api php artisan config:cache
$C exec -T api php artisan route:cache
$C exec -T api php artisan queue:restart
echo "Deployed $RELEASE"
