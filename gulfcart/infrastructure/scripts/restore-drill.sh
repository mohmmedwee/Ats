#!/bin/sh
# Monthly restore drill: restore the latest dump into a scratch database and run sanity checks.
# Usage: PGHOST=... PGUSER=... PGPASSWORD=... BACKUP_DIR=/backups ./restore-drill.sh
set -eu
: "${BACKUP_DIR:=/backups}"
latest=$(ls -1t "$BACKUP_DIR"/*.dump | head -n 1)
scratch="restore_drill_$(date -u +%Y%m%d)"
createdb "$scratch"
trap 'dropdb --if-exists "$scratch"' EXIT
pg_restore --no-owner --dbname="$scratch" "$latest"
psql -v ON_ERROR_STOP=1 -d "$scratch" -tAc "select count(*) from orders" > /dev/null
psql -v ON_ERROR_STOP=1 -d "$scratch" -tAc "select count(*) from payments where refunded_amount > amount" | grep -qx 0
echo "restore drill ok from $latest"
