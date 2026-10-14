#!/usr/bin/env bash
# Runs the checks of .github/workflows/ci.yml, in the same order, in one development environment.
# Usage: .dev/ci/run.sh <ver> <db>     (SECBK_PHP selects another PHP version, as in .dev/env/env.sh)
# Logs go to .dev/ci/out/<ver>-<db>[-php<version>]/. Exits non-zero when any check fails.
set -uo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
name="$secbk_ver-$secbk_db${SECBK_PHP:+-php$SECBK_PHP}"
out="$repo/.dev/ci/out/$name"
rm -rf "${out:?}"
mkdir -p "$out"
ci=/opt/moodle-plugin-ci/bin/moodle-plugin-ci
plugin="/var/www/html/$SECBK_PLUGIN_PATH"
run() { "$repo/.dev/env/run.sh" "$secbk_ver" "$secbk_db" "$@"; }
failed=()

step() {
    local label="$1"
    shift
    echo "== $label"
    if "$@" > "$out/$label.log" 2>&1; then
        echo "   ok"
    else
        echo "   FAILED (see $out/$label.log)"
        failed+=("$label")
    fi
}

step phplint run php "$ci" phplint "$plugin"
run php "$ci" phpmd "$plugin" > "$out/phpmd.log" 2>&1 || true
step phpcs run php "$ci" phpcs --max-warnings 0 "$plugin"
step phpdoc run php "$ci" phpdoc --max-warnings 0 --moodle /var/www/html "$plugin"
step validate run php "$ci" validate --moodle /var/www/html "$plugin"
step savepoints run php "$ci" savepoints "$plugin"
step mustache run php "$ci" mustache --moodle /var/www/html "$plugin"
step grunt run php "$ci" grunt --max-lint-warnings 0 --moodle /var/www/html "$plugin"
# PHPUnit (--fail-on-warning), Behat and the command line scripts, with coverage; fails when a test fails.
step tests-and-coverage "$repo/.dev/coverage/run.sh" "$secbk_ver" "$secbk_db"
cp -R "$repo/.dev/coverage/out/$name" "$out/coverage" 2>/dev/null || true

if [ "${#failed[@]}" -gt 0 ]; then
    echo "failed: ${failed[*]}"
    exit 1
fi
echo "all checks passed ($name)"
