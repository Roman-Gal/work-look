#!/bin/sh
set -eu
umask 077
for app_name in telegram-task-planner ukrainizator-bot; do
    case "$app_name" in
        telegram-task-planner) database_name=p1091_planner ;;
        ukrainizator-bot) database_name=p1091_translator ;;
    esac
    shared_dir="$HOME/apps/$app_name/shared"
    backup_dir="$shared_dir/backups"
    mkdir -p "$backup_dir"
    chmod 700 "$backup_dir"
    export PGPASSFILE="$shared_dir/.pgpass"
    test -r "$PGPASSFILE"
    dump_file="$backup_dir/$app_name-$(date -u +%Y%m%dT%H%M%SZ).dump"
    temporary_file="$dump_file.partial"
    trap 'rm -f "$temporary_file"' EXIT HUP INT TERM
    pg_dump --host=pgsql93.mydevil.net --port=5432 --username="$database_name" --dbname="$database_name" --no-password --no-owner --no-privileges --format=custom --file="$temporary_file"
    pg_restore --list "$temporary_file" >/dev/null
    mv "$temporary_file" "$dump_file"
    find "$backup_dir" -type f -name "$app_name-????????T??????Z.dump" -mtime +13 -delete
done
