#!/usr/bin/env bash
# Runs the command line scripts with the coverage collector (called by .dev/coverage/run.sh): the runs of .dev/ci/cli-runs.sh,
# which the step "Command line scripts" of ci.yml makes too, and the backup without --now.
# Usage: .dev/coverage/cli.sh <ver> <db>
# Every run has its own expected exit code (cli-runs.sh lists them; 2: the backup ran nothing). Any other code, 255 above all,
# is a PHP fatal error: the script goes on with the other runs, then exits 1.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
compose="$SECBK_DOCKER/bin/moodle-docker-compose"
dir="/var/www/html/$SECBK_PLUGIN_PATH/cli"
"$repo/.dev/fakesec/start.sh" "$secbk_ver" "$secbk_db"
failed=0
secbk_cli_php() {
    "$compose" exec -T -e SECBK_COVERAGE_CLI=1 webserver php "$@"
}
# shellcheck source=../ci/cli-runs.sh
source "$repo/.dev/ci/cli-runs.sh"
secbk_cli_runs "$dir"
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
