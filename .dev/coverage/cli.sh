#!/usr/bin/env bash
# Runs the command line scripts with the coverage collector (called by .dev/coverage/run.sh).
# Usage: .dev/coverage/cli.sh <ver> <db>
# Every run has its own expected exit code (0: ok or help, 1: an unknown option or a wrong URL). Any other code, 255 above all,
# is a PHP fatal error: the script goes on with the other runs, then exits 1.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
compose="$SECBK_DOCKER/bin/moodle-docker-compose"
script="/var/www/html/$SECBK_PLUGIN_PATH/cli/diagnose.php"
"$repo/.dev/fakesec/start.sh" "$secbk_ver" "$secbk_db"
failed=0
# check <expected exit code> [argument]
check() {
    local expected="$1" code=0
    shift
    "$compose" exec -T -e SECBK_COVERAGE_CLI=1 webserver php "$script" "$@" || code=$?
    if [ "$code" != "$expected" ]; then
        echo "diagnose.php $* ended with exit code $code, expected $expected" >&2
        failed=1
    fi
}
check 0 --help
check 1 --no-such-option
check 0
check 0 --endpoint=http://localhost:8099/api/lms-backup/v1
check 1 --endpoint=ftp://example.org/api
exit "$failed"
