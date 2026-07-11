#!/usr/bin/env sh
set -eu

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
destination="${BACKUP_DIR:-/backups}/$timestamp"
mkdir -p "$destination"

MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --host="${DB_HOST:-mysql}" \
    --user="$DB_USERNAME" \
    --single-transaction \
    --routines \
    --triggers \
    "$DB_DATABASE" | gzip > "$destination/database.sql.gz"

tar -C /var/www/html/storage/app -czf "$destination/storage-app.tar.gz" private public
sha256sum "$destination/database.sql.gz" "$destination/storage-app.tar.gz" > "$destination/SHA256SUMS"

echo "Backup created at $destination"
