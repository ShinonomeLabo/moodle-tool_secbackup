#!/usr/bin/env bash
# Tests for .dev/ci/cli-runs.sh, the runs of the command line scripts that the coverage counts, and a check that the two
# places that run them, the step "Command line scripts" of the job test of ci.yml and .dev/coverage/cli.sh, take them from
# there. Each place had a list of its own, and a run added to one only was missing from the coverage of the other. The one
# run that the two places make differently, the backup without --now, is the only run of their own that they may have.
# Run: bash .dev/coverage/tests/cli_runs.sh    (SECBK_CI_YML names another ci.yml, as for .dev/ci/matrix.sh)
# Development only; not part of the plugin package.
# shellcheck disable=SC2016 # The single-quoted texts are lines of the files, with their variables unexpanded.
# shellcheck disable=SC2329 # The stubs of secbk_cli_php are called by the runs of the library.
set -u
repo="$(cd "$(dirname "$0")/../../.." && pwd)"
lib="$repo/.dev/ci/cli-runs.sh"
ciyml="${SECBK_CI_YML:-$repo/.github/workflows/ci.yml}"
clish="$repo/.dev/coverage/cli.sh"
work="$(mktemp -d)" || exit 2
trap 'rm -rf "$work"' EXIT
pass=0
fail=0

# expect <label> <wanted> <got>
expect() {
    if [ "$2" = "$3" ]; then
        pass=$((pass + 1))
    else
        fail=$((fail + 1))
        echo "FAIL: $1"
        echo "   wanted: $(printf '%s' "$2" | tr '\n' '|')"
        echo "   got:    $(printf '%s' "$3" | tr '\n' '|')"
    fi
}

# The lines of the script of the step "Command line scripts" of ci.yml, without their indentation.
ci_step() {
    awk '
        /^      - name: / { inside = ($0 == "      - name: Command line scripts") ; inrun = 0; next }
        inside && /^        run: \|$/ { inrun = 1; next }
        inside && inrun && /^          / { sub(/^          /, ""); print; next }
        inside && inrun && /^ *$/ { next }
        inside && inrun { inrun = 0 }
    ' "$ciyml"
}

# The lines of a caller that run a script of their own: a call of check, or a line that names restore.php.
own_runs() {
    sed -e 's/^[[:space:]]*//' | grep -E '^check [0-9]|restore\.php' || true
}

# The two callers take the runs from the library and run only the backup without --now themselves.
step="$(ci_step)"
expect "ci.yml has the step Command line scripts" yes "$([ -n "$step" ] && echo yes || echo no)"
expect "the step of ci.yml sources the runs" 1 "$(printf '%s\n' "$step" | grep -cxF 'source plugin/.dev/ci/cli-runs.sh')"
expect "the step of ci.yml makes the runs once" 1 "$(printf '%s\n' "$step" | grep -cxF 'secbk_cli_runs "$dir"')"
expect "the step of ci.yml runs only the backup without --now itself" 'check 2 "$dir/backup.php"' \
    "$(printf '%s\n' "$step" | own_runs)"
expect "cli.sh sources the runs" 1 "$(grep -cxF 'source "$repo/.dev/ci/cli-runs.sh"' "$clish")"
expect "cli.sh makes the runs once" 1 "$(grep -cxF 'secbk_cli_runs "$dir"' "$clish")"
expect "cli.sh runs only the backup without --now itself" 'check 2 "$dir/backup.php"' "$(own_runs < "$clish")"

# check: the exit code of the run decides, and a wrong one is told with the script, its arguments and both codes.
# shellcheck source=../../ci/cli-runs.sh
if ! source "$lib"; then
    echo "FAIL: $lib cannot be sourced"
    exit 1
fi
secbk_cli_php() { return 3; }
failed=0
check 3 /cli/restore.php --extract 2> "$work/err" < /dev/null
expect "the expected exit code passes" "0:" "$failed:$(cat "$work/err")"
failed=0
check 1 /cli/restore.php --extract 2> "$work/err" < /dev/null
expect "another exit code fails" "1:restore.php --extract ended with exit code 3, expected 1" "$failed:$(cat "$work/err")"

# check_json: every line on the standard output of the script is JSON, and there is one at least.
for case in 'ok:0:{"type":"error","code":"restore_keys_invalid"}' 'no line:1:' 'a warning:1:PHP Warning: x' \
    'JSON, then a warning:1:{"type":"keys_ready"}\nNotice: y'; do
    label="${case%%:*}"
    rest="${case#*:}"
    wanted="${rest%%:*}"
    lines="${rest#*:}"
    secbk_cli_php() { printf '%b' "$lines"; }
    failed=0
    check_json /cli/restore.php --extract 2> /dev/null
    expect "check_json: $label" "$wanted" "$failed"
done
secbk_cli_php() { printf '%s\n' 'Including local options from x' '{"type":"done"}'; }
failed=0
check_json /cli/restore.php --extract 2> /dev/null
expect "check_json leaves out the line of moodle-docker-compose" 0 "$failed"

# secbk_cli_runs runs each script, with the extraction and the import among them, and its JSON checks give PHP's messages the
# standard output, so that they would show there.
secbk_cli_php() { printf '%s\n' "$*" >> "$work/calls"; printf '{}\n'; return 0; }
failed=0
secbk_cli_runs /cli > /dev/null 2>&1
calls="$(cat "$work/calls")"
for wanted in '/cli/diagnose.php --help' '/cli/backup.php --help' '/cli/restore.php --extract' \
    '/cli/restore.php --import --staging=/nonexistent/secbackup-staging' \
    '-d display_errors=stdout -d error_reporting=-1 /cli/restore.php --extract'; do
    expect "secbk_cli_runs runs: $wanted" 1 "$(printf '%s\n' "$calls" | grep -cxF -- "$wanted")"
done

echo "cli runs: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
