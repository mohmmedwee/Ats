#!/bin/sh
# Daily logical backup (in addition to WAL archiving / managed PITR). Encrypt and ship off-site.
# Usage: PGHOST=... PGUSER=... PGPASSWORD=... PGDATABASE=gulfcart BACKUP_DIR=/backups ./backup-postgres.sh
set -eu
: "${PGDATABASE:=gulfcart}"
: "${BACKUP_DIR:=/backups}"
: "${RETENTION_DAYS:=14}"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
file="$BACKUP_DIR/${PGDATABASE}-${stamp}.dump"
mkdir -p "$BACKUP_DIR"
pg_dump --format=custom --no-owner --no-privileges --file="$file" "$PGDATABASE"
pg_restore --list "$file" > /dev/null   # verify the archive is readable
find "$BACKUP_DIR" -name "${PGDATABASE}-*.dump" -mtime +"$RETENTION_DAYS" -delete
echo "backup ok: $file"
