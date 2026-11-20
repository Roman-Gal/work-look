#!/bin/sh
# Start exactly one native FreeBSD bot supervisor, only after valid configuration.
# Validation is local; placeholder environments never contact Telegram or DeepL.
set -eu
umask 077
case "${1:-}" in
    planner)
        current="$HOME/apps/telegram-task-planner/current"
        shared="$HOME/apps/telegram-task-planner/shared"
        cd "$current"
        if ! /usr/local/bin/node24 --env-file=.env --input-type=module -e 'import { loadConfig } from "./src/config.js"; loadConfig();' >/dev/null 2>&1; then
            printf '%s\n' 'Planner standby: external credentials are not configured.'
            exit 0
        fi
        set -- /usr/local/bin/node24 --env-file=.env src/index.js
        ;;
    translator)
        current="$HOME/apps/ukrainizator-bot/current"
        shared="$HOME/apps/ukrainizator-bot/shared"
        python_bin="$HOME/apps/ukrainizator-bot/venv/bin/python"
        cd "$current"
        if ! "$python_bin" -c 'from translator.config import Settings; Settings.from_env().validate()' >/dev/null 2>&1; then
            printf '%s\n' 'Translator standby: external credentials are not configured.'
            exit 0
        fi
        set -- "$python_bin" -m translator
        ;;
    *) printf '%s\n' 'Usage: bot-service.sh planner|translator' >&2; exit 2 ;;
esac
exec /usr/sbin/daemon -f -r -R 10 -p "$shared/child.pid" -P "$shared/supervisor.pid" -o "$shared/service.log" "$@"
