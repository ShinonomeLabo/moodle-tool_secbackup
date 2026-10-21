#!/usr/bin/env bash
# Runs the command line scripts of the plugin on a site with developer debugging and fails on a PHP message (a warning, a
# notice, a deprecation, an error) or a debugging() message in their output. The site is registered with the fake backup
# service first (.dev/fakesec/prepare.php), so that cli/diagnose.php checks a registered site and cli/backup.php --now makes a
# whole run to the end.
# The site must run with developer debugging: $CFG->debug = E_ALL and $CFG->debugdisplay = 1, as the config.php that
# moodle-plugin-ci writes has. A canary (debugging() calls at the developer level, one of them with a message of two lines,
# and an undefined variable, in a script that loads the same config.php) must be found by the check first, so that neither a
# site without developer debugging nor a check that sees nothing can pass.
# The job test of ci.yml runs it on the site itself (not on the PHPUnit or the Behat site), after the other command line
# scripts, because it leaves the site registered. It is not run on a development site, where it would replace a registration
# made by hand.
# Usage: .dev/ci/backup-developer-debug.sh <Moodle folder> [php]
set -euo pipefail
moodle="${1:?usage: backup-developer-debug.sh <Moodle folder> [php]}"
php="${2:-php}"
if [ -d "$moodle/public" ]; then docroot="$moodle/public"; else docroot="$moodle"; fi
plugin="$docroot/admin/tool/secbackup"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The first line that PHP writes for a message with display_errors (Warning: ...) or log_errors (PHP Warning: ...), and the
# first line that debugging() writes on the command line (++ message ++). Only the start of the line is matched: a message
# with a line feed in it ends on a later line (" on line N", " ++"). No line of the normal output of the scripts starts this
# way (checked on all 18 combinations of CI run 39622090686: the lines start with a word, "[" or "==").
pattern='^(PHP )?(Warning|Notice|Deprecated|Strict Standards|Fatal error|Parse error|Recoverable fatal error|Core Warning|Compile Warning|User Warning|User Notice|User Deprecated|User Error): |^\+\+ '

cat > "$work/canary.php" <<PHP
<?php
define('CLI_SCRIPT', true);
require('$moodle/config.php');
debugging('canary of backup-developer-debug.sh', DEBUG_DEVELOPER);
debugging("canary of two lines\\nof backup-developer-debug.sh", DEBUG_DEVELOPER);
echo \$canary_undefined;
PHP
"$php" "$work/canary.php" > "$work/canary.out" 2>&1 || true
grep -E "$pattern" "$work/canary.out" > "$work/canary.found" || true
if ! grep -q -x -F '++ canary of backup-developer-debug.sh ++' "$work/canary.found" \
        || ! grep -q -x -F '++ canary of two lines' "$work/canary.found" \
        || ! grep -q 'canary_undefined' "$work/canary.found"; then
    cat "$work/canary.out"
    echo "The check did not find the debugging() messages and the warning of the canary above: either the site does not run" \
        "with developer debugging (\$CFG->debug = E_ALL, \$CFG->debugdisplay = 1) or the check could not find other messages." >&2
    exit 1
fi

failed=0
# run <expected exit code> <command...>: appends the output of the command to the log and checks its exit code.
run() {
    local expected="$1" code=0
    shift
    echo "== ${*#"$plugin/"}" >> "$work/output"
    "$@" >> "$work/output" 2>&1 || code=$?
    if [ "$code" != "$expected" ]; then
        echo "${*#"$plugin/"} ended with exit code $code, expected $expected" >&2
        failed=1
    fi
}
run 0 "$php" "$plugin/.dev/fakesec/prepare.php"
run 0 "$php" "$plugin/cli/diagnose.php"
# The command waits and tries again while a check of the server fails; 15 minutes are many times what a run of this site needs.
run 0 timeout 900 "$php" "$plugin/cli/backup.php" --now
cat "$work/output"
# One message of Moodle itself is expected: email_to_user() calls debugging() when $CFG->noemailever is set, which the
# config.php of moodle-plugin-ci does, and the registration sends a message to the site administrators. A site that sends mail
# does not show it.
grep -n -E "$pattern" "$work/output" > "$work/found" || true
# shellcheck disable=SC2016
grep -v -x -E '[0-9]+:\+\+ Not sending email due to \$CFG->noemailever config setting \+\+' "$work/found" > "$work/unexpected" || true
if [ -s "$work/unexpected" ]; then
    cat "$work/unexpected"
    echo "The lines above are PHP messages or debugging() messages of the scripts." >&2
    failed=1
fi
exit "$failed"
