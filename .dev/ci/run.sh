#!/usr/bin/env bash
# Runs the checks of .github/workflows/ci.yml, in the same order, in one development environment.
# Usage: .dev/ci/run.sh <ver> <db>     (SECBK_PHP selects another PHP version, as in .dev/env/env.sh)
# SECBK_BROWSER=chrome|firefox|edge|safari selects the browser of Behat (default chrome). Chrome runs every check of the
# `test` job, with the coverage. The other browsers run the checks of the `inventory` job and the Behat features of the
# `behat` and `behat-safari` jobs, in that browser (see .dev/env/behat.sh for what each browser needs), without coverage.
# Logs go to .dev/ci/out/<ver>-<db>[-php<version>][-<browser>]/. Exits non-zero when any check fails.
set -uo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
browser="${SECBK_BROWSER:-chrome}"
name="$secbk_ver-$secbk_db${SECBK_PHP:+-php$SECBK_PHP}"
out_name="$name"
if [ "$browser" != chrome ]; then
    out_name="$name-$browser"
fi
out="$repo/.dev/ci/out/$out_name"
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
# less than CI does. The files are those that git tracks and the new ones that it does not ignore: what CI checks out, and
# what can be committed next. Folders that git ignores (the outputs of the tools, the work folders of a clone) are not seen.
grunt_equivalent() {
    local files other
    files="$(git -C "$repo" ls-files -co --exclude-standard)" || return 1
    other="$(printf '%s\n' "$files" | grep -vE '^(\.dev|tests)/' | grep -E '(^|/)(amd|yui)/src/|\.(css|scss|less)$' || true)"
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

# The job `inventory`: every page and operation has a Behat scenario.
step ui-inventory run php "$plugin/.dev/ci/check-ui-inventory.php" "$plugin"
if [ "$browser" = chrome ]; then
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
    # The last run of the step "Command line scripts": the diagnostics under open_basedir.
    step open-basedir "$repo/.dev/ci/diagnose-open-basedir.sh" /var/www/html /var/www/moodledata "$plugin/cli/diagnose.php" \
        "$repo/.dev/env/run.sh" "$secbk_ver" "$secbk_db" php
else
    # The jobs `behat` (firefox, edge) and `behat-safari`.
    step "behat-$browser" "$repo/.dev/env/behat.sh" "$secbk_ver" "$secbk_db"
fi

if [ "${#failed[@]}" -gt 0 ]; then
    echo "failed: ${failed[*]}"
    exit 1
fi
echo "all checks passed ($out_name)"
