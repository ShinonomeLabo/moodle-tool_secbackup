#!/usr/bin/env bash
# Runs the command line scripts with the coverage collector (called by .dev/coverage/run.sh).
# Usage: .dev/coverage/cli.sh <ver> <db>
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
compose="$SECBK_DOCKER/bin/moodle-docker-compose"
script="/var/www/html/$SECBK_PLUGIN_PATH/cli/diagnose.php"
"$repo/.dev/fakesec/start.sh" "$secbk_ver" "$secbk_db"
runs=(
    "--help"
    "--no-such-option"
    ""
    "--endpoint=http://localhost:8099/api/lms-backup/v1"
    "--endpoint=ftp://example.org/api"
)
for args in "${runs[@]}"; do
    if [ -z "$args" ]; then
        "$compose" exec -T -e SECBK_COVERAGE_CLI=1 webserver php "$script" || true
    else
        "$compose" exec -T -e SECBK_COVERAGE_CLI=1 webserver php "$script" "$args" || true
    fi
done
