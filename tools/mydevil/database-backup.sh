#!/bin/sh
# Native FreeBSD PostgreSQL backup. Credentials are read from a mode-0600 .pgpass, never argv.
set -eu
umask 077
shared_dir="$HOME/apps/work-look/shared"
backup_dir="$shared_dir/backups"
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
export PGPASSFILE="$shared_dir/.pgpass"
test -r "$PGPASSFILE"
dump_file="$backup_dir/worklook-$(date -u +%Y%m%dT%H%M%SZ).dump"
temporary_file="$dump_file.partial"
trap 'rm -f "$temporary_file"' EXIT HUP INT TERM
pg_dump --host=pgsql93.mydevil.net --port=5432 --username=p1091_worklook --dbname=p1091_worklook --no-password --no-owner --no-privileges --format=custom --file="$temporary_file"
pg_restore --list "$temporary_file" >/dev/null
mv "$temporary_file" "$dump_file"
# Only our dated, completed dumps in this fixed private directory; keep at least 14 days.
find "$backup_dir" -type f -name 'worklook-????????T??????Z.dump' -mtime +13 -delete
