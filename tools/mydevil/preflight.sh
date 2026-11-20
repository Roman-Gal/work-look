#!/bin/sh
# Read-only inventory. Run after SSH authentication: sh tools/mydevil/preflight.sh
set -u
printf 'OS: '; uname -srm
printf 'Account: '; id -un
printf 'Home: %s\n' "$HOME"
printf 'Working directory: '; pwd
for command_name in git php84 php85 composer node22 node24 npm22 npm24 python3.11 python3.12 python3.13 python3.14 flock lockf daemon curl fetch devil; do
    command_path=$(command -v "$command_name" 2>/dev/null || true)
    if [ -n "$command_path" ]; then printf '%s=%s\n' "$command_name" "$command_path"; fi
done
for php_command in php84 php85; do
    if command -v "$php_command" >/dev/null 2>&1; then
        "$php_command" -r 'echo PHP_VERSION, " extensions: "; foreach (["pdo_sqlite", "pdo_pgsql", "mbstring", "dom", "simplexml", "intl", "gd"] as $extension) { echo $extension, "=", extension_loaded($extension) ? "yes " : "no "; } echo PHP_EOL;'
        break
    fi
done
for node_command in node24 node22; do
    if command -v "$node_command" >/dev/null 2>&1; then
        "$node_command" --input-type=module -e 'import { DatabaseSync } from "node:sqlite"; const db = new DatabaseSync(":memory:"); console.log("Node", process.version, "SQLite", db.prepare("select sqlite_version() as version").get().version); db.close();'
        break
    fi
done
for python_command in python3.12 python3.11 python3.13; do
    if command -v "$python_command" >/dev/null 2>&1; then
        "$python_command" -c 'import sys,sqlite3,importlib.util; print("Python",sys.version.split()[0],"SQLite",sqlite3.sqlite_version); print("Existing modules:",{n:importlib.util.find_spec(n) is not None for n in ["venv","pip","lingua","ctranslate2","onnxruntime","libretranslate"]})'
        break
    fi
done
if command -v devil >/dev/null 2>&1; then
    printf '\nConfigured websites:\n'; devil www list || true
    printf '\nReserved ports:\n'; devil port list || true
fi
printf '\nExisting domain directories:\n'
if [ -d "$HOME/domains" ]; then find "$HOME/domains" -mindepth 1 -maxdepth 1 -type d -print; fi
printf '\nDisk space:\n'; df -h "$HOME"
printf '\nCurrent cron entry count (contents kept private):\n'
crontab -l 2>/dev/null | awk '!/^[[:space:]]*(#|$)/ { count++ } END { print count+0 }'
