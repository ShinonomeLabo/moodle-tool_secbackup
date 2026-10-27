#!/usr/bin/env bash
# Runs the command line scripts with the coverage collector (called by .dev/coverage/run.sh).
# Usage: .dev/coverage/cli.sh <ver> <db>
# Every run has its own expected exit code (0: ok or help, 1: an unknown option, a wrong URL or a restore that gets no keys,
# 2: the backup ran nothing). Any other code, 255 above all, is a PHP fatal error: the script goes on with the other runs, then
# exits 1.
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
check 0 "$dir/backup.php" --help
check 0 "$dir/restore.php" --help
check 1 "$dir/restore.php" --no-such-option
# The extraction with nothing on its input: it gives its public key, finds no keys and ends with the error line (1).
check 1 "$dir/restore.php" --extract </dev/null
# Whatever PHP shows goes to the error stream: every line on the standard output of the extraction is JSON.
stdout="$("$compose" exec -T -e SECBK_COVERAGE_CLI=1 webserver php -d display_errors=stdout -d error_reporting=-1 \
    "$dir/restore.php" --extract </dev/null 2>/dev/null | grep -v '^Including local options')" || true
jsoncheck='while (($l = fgets(STDIN)) !== false) { json_decode($l, true, 512, JSON_THROW_ON_ERROR); }'
if [ -z "$stdout" ] || ! printf '%s\n' "$stdout" | php -r "$jsoncheck"; then
    echo "restore.php --extract wrote a line that is not JSON to the standard output" >&2
    failed=1
fi
check 1 "$dir/backup.php" --no-such-option
# Without --now the backup goes on with a running one until it ends, so the plain run is made only on a site that has none:
# then it runs nothing (2). A site with a running backup (or a check that fails) fails this script without that run.
live="$("$compose" exec -T webserver php -r "define('CLI_SCRIPT', true); require('$dir/../../../../config.php');
    echo \\tool_secbackup\\local\\backup\\run_record::active() === null ? 'none' : 'live', PHP_EOL;" 2>&1 | tail -n 1 | tr -d '\r')" || true
if [ "$live" = none ]; then
    check 2 "$dir/backup.php"
else
    echo "backup.php was not run without --now: the development site has a running backup, or it could not be checked ($live)." >&2
    failed=1
fi
exit "$failed"
