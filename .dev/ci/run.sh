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

# moodle-plugin-ci grunt cannot run here: the web server image has no Node.js, and the command backs up the plugin folder
# and mirrors it back, which here is this repository with its .git. No check may write to the repository, so the step runs
# the one Grunt task that applies to this plugin, the Gherkin lint of tests/behat/*.feature, with Moodle's configuration
# and the version of gherkin-lint that the Moodle branch pins. gherkin-lint is installed once under .dev/ci/out/tools.
# The step fails when the plugin has a kind of file for which CI runs another Grunt task, so that this step never covers
# less than CI does.
grunt_equivalent() {
    local other
    other="$(cd "$repo" && find . \( -path ./.git -o -path ./.dev -o -path ./tests \) -prune -o \
        \( -path '*/amd/src/*' -o -path '*/yui/src/*' -o -name '*.css' -o -name '*.scss' -o -name '*.less' \) -print)"
    if [ -n "$other" ]; then
        echo "The plugin has files for Grunt tasks that this script does not run (amd, yui, css, scss, less):"
        echo "$other"
        return 1
    fi
    local version tools="$repo/.dev/ci/out/tools/gherkin-lint"
    version="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["packages"]["node_modules/gherkin-lint"]["version"];' \
        "$SECBK_MOODLE/npm-shrinkwrap.json")" || return 1
    if [ ! -x "$tools/node_modules/.bin/gherkin-lint" ] || ! grep -q "\"version\": \"$version\"" "$tools/node_modules/gherkin-lint/package.json"; then
        rm -rf "${tools:?}"
        mkdir -p "$tools"
        npm install --prefix "$tools" --no-audit --no-fund --ignore-scripts "gherkin-lint@$version" || return 1
    fi
    echo "gherkin-lint $version, configuration $SECBK_MOODLE/.gherkin-lintrc"
    "$tools/node_modules/.bin/gherkin-lint" -c "$SECBK_MOODLE/.gherkin-lintrc" "$repo/tests/behat/*.feature"
}

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
step grunt grunt_equivalent
# PHPUnit (--fail-on-warning), Behat and the command line scripts, with coverage; fails when a test fails.
step tests-and-coverage "$repo/.dev/coverage/run.sh" "$secbk_ver" "$secbk_db"
cp -R "$repo/.dev/coverage/out/$name" "$out/coverage" 2>/dev/null || true

if [ "${#failed[@]}" -gt 0 ]; then
    echo "failed: ${failed[*]}"
    exit 1
fi
echo "all checks passed ($name)"
