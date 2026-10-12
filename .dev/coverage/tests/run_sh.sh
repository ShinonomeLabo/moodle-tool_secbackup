#!/usr/bin/env bash
# Tests for pieces of .dev/coverage/run.sh. The functions are cut out of the script, and the end of the run (the check of
# the reports of the collector) is run on its own, so that no environment is needed.
# Run: bash .dev/coverage/tests/run_sh.sh [path of run.sh]
# Development only; not part of the plugin package.
set -u
target="${1:-$(cd "$(dirname "$0")/.." && pwd)/run.sh}"
work="$(mktemp -d)"
trap '[ -n "$work" ] && rm -rf "$work"' EXIT
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

# outside_release_paths: the paths of "git status --porcelain" that are outside .dev/ and tests/.
eval "$(sed -n '/^outside_release_paths() {/,/^}/p' "$target")"
if ! declare -F outside_release_paths > /dev/null; then
    echo "FAIL: outside_release_paths is not defined in $target"
    fail=$((fail + 1))
else
    status=$' M .dev/coverage/merge.php\n?? tests/new_test.php\nA  lib.php\n M README.md\nR  tests/a.php -> classes/a.php\n'
    status+=$'R  classes/old.php -> tests/new.php\n?? "classes/with space.php"\n?? ".dev/with space.php"\n D version.php\n'
    status+=$'?? .dev/coverage/x\n?? .devious/x\n?? testsuite/x\n'
    expect "an invented status" \
        $'lib.php\nREADME.md\nclasses/a.php\nclasses/old.php\nclasses/with space.php\nversion.php\n.devious/x\ntestsuite/x' \
        "$(outside_release_paths "$status")"
    expect "an empty status" "" "$(outside_release_paths "")"
    expect "changes in .dev/ and tests/ only" "" \
        "$(outside_release_paths $' M .dev/a.php\n?? tests/b.php\nA  tests/behat/c.feature')"

    # The real output of git, with names that need quotes, a rename, a deletion and a staged file.
    repo="$work/repo"
    mkdir -p "$repo/classes" "$repo/tests" "$repo/.dev"
    git -C "$repo" init -q
    git -C "$repo" config user.email test@example.com
    git -C "$repo" config user.name test
    echo a > "$repo/version.php"
    echo a > "$repo/lang.php"
    echo a > "$repo/gone.php"
    echo a > "$repo/tests/t.php"
    git -C "$repo" add -A
    git -C "$repo" commit -q -m init
    echo b >> "$repo/version.php"
    echo b > "$repo/tests/ok.php"
    echo b > "$repo/.dev/ok.php"
    echo b > "$repo/classes/new file.php"
    echo b > "$repo/classes/staged.php"
    git -C "$repo" add classes/staged.php
    git -C "$repo" mv lang.php classes/renamed.php
    git -C "$repo" rm -q gone.php
    echo b >> "$repo/tests/t.php"
    status="$(git -C "$repo" status --porcelain --untracked-files=all)"
    expect "the real status of git" \
        $'classes/new file.php\nclasses/renamed.php\nclasses/staged.php\ngone.php\nlang.php\nversion.php' \
        "$(outside_release_paths "$status" | sort)"
fi

# stop_collecting: takes the settings and the flag away, and says so when it cannot.
eval "$(sed -n '/^stop_collecting() {/,/^}/p' "$target")"
if ! declare -F stop_collecting > /dev/null; then
    echo "FAIL: stop_collecting is not defined in $target"
    fail=$((fail + 1))
else
    raw="$work/raw"
    mkdir -p "$raw"
    ini=/usr/local/etc/php/conf.d/zz-secbk-coverage.ini
    # These are read by stop_collecting, which is cut out of run.sh.
    # shellcheck disable=SC2034
    secbk_ver=503 secbk_db=pgsql repo=/repo COMPOSE_PROJECT_NAME=stubproject SECBK_DOCKER=/docker
    restart_web() {
        echo restarted >> "$work/calls"
        return 0
    }
    run() {
        echo "run $*" >> "$work/calls"
        return "${RUN_STATUS:-0}"
    }
    calls() { tr '\n' '|' < "$work/calls" | sed 's/|$//'; }

    : > "$work/calls"
    touch "$raw/ENABLED"
    collecting=1
    RUN_STATUS=0
    stop_collecting
    status=$?
    expect "ENABLED is removed" "no" "$([ -e "$raw/ENABLED" ] && echo yes || echo no)"
    expect "the settings are removed, then the web server is restarted" "run sh -c rm -f $ini|restarted" "$(calls)"
    expect "the status is 0" "0" "$status"

    # The settings cannot be removed (the container is stopped): no restart, status 1, and a message that says what to do.
    : > "$work/calls"
    touch "$raw/ENABLED"
    collecting=1
    RUN_STATUS=1
    err="$(stop_collecting 2>&1 > /dev/null)"
    status=$?
    expect "no restart when the settings stay" "run sh -c rm -f $ini" "$(calls)"
    expect "the status is 1 when the settings stay" "1" "$status"
    expect "the settings are named" "yes" \
        "$([[ "$err" == *"$ini are still in the web server container of stubproject"* ]] && echo yes || echo no)"
    expect "the command to remove them is given" "yes" \
        "$([[ "$err" == *"/repo/.dev/env/run.sh 503 pgsql rm -f $ini"* ]] && echo yes || echo no)"
    expect "the command to restart is given" "yes" \
        "$([[ "$err" == *"/docker/bin/moodle-docker-compose restart webserver"* ]] && echo yes || echo no)"

    # shellcheck disable=SC2034
    collecting=0
    : > "$work/calls"
    stop_collecting
    expect "nothing happens when the collection is off" "" "$(cat "$work/calls")"
fi

# The end of the run: a report that is lost or half written must stop the run before the merge.
block="$(sed -n '/^# A report that is lost makes the merge count fewer lines/,/^mv /p' "$target")"
if [ -z "$block" ]; then
    echo "FAIL: the check of the reports is not in $target"
    fail=$((fail + 1))
else
    check_end() { # <behat status> <json files> <tmp files>: prints "<exit status>|<output>"
        local raw="$work/rawc" out="$work/outc" file
        rm -rf "$raw" "$out"
        mkdir -p "$raw" "$out"
        for file in $2; do echo '{}' > "$raw/$file"; done
        for file in $3; do echo '{' > "$raw/$file"; done
        printf '%s\nbehat_status=%s\nraw="%s"\nout="%s"\n%s\necho reached-the-merge\n' \
            'set -euo pipefail' "$1" "$raw" "$out" "$block" > "$work/block.sh"
        local result code
        result="$(bash "$work/block.sh" 2>&1)"
        code=$?
        printf '%s|%s' "$code" "$result"
    }
    raw="$work/rawc"
    expect "no report: the run stops and says so" \
        "1|the run left no JSON report in $raw: the collector recorded nothing" "$(check_end 0 "" "")"
    expect "no report and Behat failed: both are said" \
        "1|the run left no JSON report in $raw: the collector recorded nothing"$'\n'"Behat failed (exit 7)" "$(check_end 7 "" "")"
    expect "a report: moved to out/, the run goes on" "0|reached-the-merge" "$(check_end 0 "web-1.json" "")"
    expect "the report is in out/" "web-1.json" "$(ls "$work/outc")"
    expect "a report that was being written: the run stops and names it" \
        "1|a request was cut off while the collector wrote its report: $raw/web-2.json.tmp" "$(check_end 0 "" "web-2.json.tmp")"
    expect "a report that was being written next to a good one: the run stops" \
        "1|a request was cut off while the collector wrote its report: $raw/web-2.json.tmp" \
        "$(check_end 0 "web-1.json" "web-2.json.tmp")"
fi

echo "run.sh pieces: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
