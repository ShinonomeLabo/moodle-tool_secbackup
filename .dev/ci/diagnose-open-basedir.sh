#!/usr/bin/env bash
# Runs the connection diagnostics of the command line under open_basedir, which hides /proc and /sys from PHP, and checks that
# what the diagnostics cannot read is reported as unknown, without a PHP message: is_file() warns for a path outside
# open_basedir, so the error handler of classes/local/diagnostics/system_files.php has to be in place before it.
# The job `test` of .github/workflows/ci.yml and .dev/ci/run.sh run it.
# Usage: diagnose-open-basedir.sh <Moodle root> <data directory> <diagnose.php> <command that runs PHP...>
#   in CI:     diagnose-open-basedir.sh "$GITHUB_WORKSPACE/moodle" "$GITHUB_WORKSPACE/moodledata" "$cli" php
#   in Docker: diagnose-open-basedir.sh /var/www/html /var/www/moodledata <plugin>/cli/diagnose.php .dev/env/run.sh 503 pgsql php
# The run passes when it ends with exit code 0, writes nothing to the standard error and no PHP message to the standard output,
# and the rows of SELinux, AppArmor and the data directory say Unknown. Moodle sets display_errors from $CFG->debugdisplay
# (on in CI and in .dev/env), so a warning goes to the standard output there: both outputs are read. The site must not be
# registered and its language is English; no URL is given, so nothing is sent. The coverage collector is not loaded
# (auto_prepend_file is emptied): it lives outside open_basedir in CI.
set -uo pipefail
root="${1:?Moodle root}"
data="${2:?data directory}"
script="${3:?diagnose.php}"
shift 3
[ "$#" -gt 0 ] || { echo "usage: $0 <Moodle root> <data directory> <diagnose.php> <command that runs PHP...>" >&2; exit 2; }
out="$(mktemp)" || exit 1
err="$(mktemp)" || exit 1
trap 'rm -f "$out" "$err"' EXIT
code=0
"$@" -d "open_basedir=$root:$data:/tmp" -d error_reporting=-1 -d display_errors=stderr -d auto_prepend_file= "$script" \
    > "$out" 2> "$err" < /dev/null || code=$?
failed=0
if [ "$code" -ne 0 ]; then
    echo "diagnose.php under open_basedir ended with exit code $code, expected 0" >&2
    failed=1
fi
if [ -s "$err" ]; then
    echo "diagnose.php under open_basedir wrote to the standard error:" >&2
    cat "$err" >&2
    failed=1
fi
if grep -E '^(PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards): ' "$out" >&2; then
    echo "diagnose.php under open_basedir printed PHP messages (above)" >&2
    failed=1
fi
for row in SELinux AppArmor 'Data directory'; do
    if ! grep -q "^\[Unknown\] $row: " "$out"; then
        echo "diagnose.php under open_basedir did not say Unknown for $row" >&2
        failed=1
    fi
done
if [ "$failed" -ne 0 ]; then
    echo "--- standard output of the run:" >&2
    cat "$out" >&2
    exit 1
fi
echo "diagnose.php under open_basedir: exit code 0, no PHP message, SELinux, AppArmor and Data directory unknown"
