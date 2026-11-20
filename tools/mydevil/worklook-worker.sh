#!/bin/sh
# A single worker per project. Can be called by cron every minute without overlap.
set -eu
umask 077
project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
cd "$project_dir"
php_bin=${PHP_BIN:-/usr/local/bin/php84}
mkdir -p var/log
export APP_ENV=prod APP_DEBUG=0
if command -v lockf >/dev/null 2>&1; then
    exec lockf -t 0 "$project_dir/var/worker.lock" "$php_bin" bin/console messenger:consume async --env=prod --no-debug --time-limit=300 --memory-limit=128M --no-interaction >> var/log/worker.log 2>&1
fi
if command -v flock >/dev/null 2>&1; then
    exec flock -n "$project_dir/var/worker.lock" "$php_bin" bin/console messenger:consume async --env=prod --no-debug --time-limit=300 --memory-limit=128M --no-interaction >> var/log/worker.log 2>&1
fi
printf '%s\n' 'Neither flock nor FreeBSD lockf is available; refusing to start an unguarded worker.' >&2
exit 1
