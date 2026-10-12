#!/usr/bin/env bash
# Runs the plugin's PHPUnit tests. Extra arguments go to phpunit.
# Set SECBK_COVERAGE=<file> to write a Clover report (pcov) to that path inside the container. The report is made with
# the plugin's own phpunit.xml (built by Moodle from tests/coverage.php), so every shipped file is in the denominator.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
shift 2
if [ -n "${SECBK_COVERAGE:-}" ]; then
    admin="${SECBK_PLUGIN_PATH%/tool/secbackup}"
    "$here/run.sh" "$secbk_ver" "$secbk_db" php "$admin/tool/phpunit/cli/util.php" --buildcomponentconfigs >/dev/null
    "$here/run.sh" "$secbk_ver" "$secbk_db" php -d pcov.enabled=1 -d "pcov.directory=/var/www/html/$SECBK_PLUGIN_PATH" \
        vendor/bin/phpunit -c "$SECBK_PLUGIN_PATH" --coverage-clover "$SECBK_COVERAGE" "$@"
    exit 0
fi
"$here/run.sh" "$secbk_ver" "$secbk_db" php vendor/bin/phpunit --no-coverage --testsuite tool_secbackup_testsuite "$@"
