#!/usr/bin/env bash
# Runs the static checks of moodle-plugin-ci that do not need a fresh install:
# phplint, phpcs, phpdoc, validate and savepoints. Fails on any error or warning.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
ci=/opt/moodle-plugin-ci/bin/moodle-plugin-ci
plugin="/var/www/html/$SECBK_PLUGIN_PATH"
run() { "$here/run.sh" "$secbk_ver" "$secbk_db" "$@"; }

run php "$ci" phplint "$plugin"
run php "$ci" phpcs --max-warnings 0 "$plugin"
run php "$ci" phpdoc --max-warnings 0 --moodle /var/www/html "$plugin"
run php "$ci" validate --moodle /var/www/html "$plugin"
run php "$ci" savepoints "$plugin"
echo "checks passed ($secbk_ver $secbk_db)"
