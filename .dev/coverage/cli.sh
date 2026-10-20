#!/usr/bin/env bash
# Runs the command line scripts with the coverage collector (called by .dev/coverage/run.sh).
# Usage: .dev/coverage/cli.sh <ver> <db>
# Every run has its own expected exit code (0: ok or help, 1: an unknown option or a wrong URL, 2: the backup ran nothing). Any
# other code, 255 above all, is a PHP fatal error: the script goes on with the other runs, then exits 1.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
compose="$SECBK_DOCKER/bin/moodle-docker-compose"
dir="/var/www/html/$SECBK_PLUGIN_PATH/cli"
script="$dir/diagnose.php"
"$repo/.dev/fakesec/start.sh" "$secbk_ver" "$secbk_db"
failed=0
# check <expected exit code> <script> [argument...]
check() {
    local expected="$1" path="$2" code=0
    shift 2
    "$compose" exec -T -e SECBK_COVERAGE_CLI=1 webserver php "$path" "$@" || code=$?
    if [ "$code" != "$expected" ]; then
        echo "$(basename "$path") $* ended with exit code $code, expected $expected" >&2
        failed=1
    fi
}
check 0 "$script" --help
check 1 "$script" --no-such-option
check 0 "$script"
check 0 "$script" --endpoint=http://localhost:8099/api/lms-backup/v1
check 1 "$script" --endpoint=ftp://example.org/api
# Without --now the backup only goes on with a running one: the development site has none, so it runs nothing (2).
check 0 "$dir/backup.php" --help
check 1 "$dir/backup.php" --no-such-option
check 2 "$dir/backup.php"
exit "$failed"
