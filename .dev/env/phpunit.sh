#!/usr/bin/env bash
# Runs the plugin's PHPUnit tests. Extra arguments go to phpunit.
# Set SECBK_COVERAGE=<file> to write a Clover report (pcov) to that path inside the container.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
shift 2
cov=()
if [ -n "${SECBK_COVERAGE:-}" ]; then
    cov=(-d pcov.enabled=1 -d pcov.directory=. vendor/bin/phpunit --coverage-clover "$SECBK_COVERAGE")
else
    cov=(vendor/bin/phpunit --no-coverage)
fi
"$here/run.sh" "$secbk_ver" "$secbk_db" php "${cov[@]}" --testsuite tool_secbackup_testsuite "$@"
