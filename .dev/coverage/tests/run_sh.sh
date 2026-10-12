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

# nul_file <file> <entry>...: writes the entries, each ended by a NUL, as git does with -z.
nul_file() {
    local file="$1"
    shift
    printf '%s\0' "$@" > "$file"
}

# outside_release_paths: the paths of "git status --porcelain=v1 -z" that are outside .dev/ and tests/.
eval "$(sed -n '/^outside_release_paths() {/,/^}/p' "$target")"
if ! declare -F outside_release_paths > /dev/null; then
    echo "FAIL: outside_release_paths is not defined in $target"
    fail=$((fail + 1))
else
    nul_file "$work/status" ' M .dev/coverage/merge.php' '?? tests/new_test.php' 'A  lib.php' ' M README.md' \
        'R  classes/a.php' 'tests/a.php' 'R  tests/new.php' 'classes/old.php' '?? classes/with space.php' \
        '?? .dev/with space.php' ' D version.php' '?? .dev/coverage/x' '?? .devious/x' '?? testsuite/x' \
        'R  x -> tests/y.php' 'tests/b.php' 'C  tests/copy.php' 'classes/original.php' 'RM tests/m.php' 'tests/n.php'
    expect "an invented status" \
        $'lib.php\nREADME.md\nclasses/a.php\nclasses/old.php\nclasses/with space.php\nversion.php\n.devious/x\ntestsuite/x\nx -> tests/y.php\nclasses/original.php' \
        "$(outside_release_paths "$work/status")"
    nul_file "$work/status"
    expect "an empty status" "" "$(outside_release_paths "$work/status")"
    nul_file "$work/status" ' M .dev/a.php' '?? tests/b.php' 'A  tests/behat/c.feature'
    expect "changes in .dev/ and tests/ only" "" "$(outside_release_paths "$work/status")"

    # The real output of git, with a name that has an arrow, a rename, a deletion and a staged file.
    repo="$work/repo"
    mkdir -p "$repo/classes" "$repo/tests" "$repo/.dev"
    git -C "$repo" init -q
    git -C "$repo" config user.email test@example.com
    git -C "$repo" config user.name test
    echo a > "$repo/version.php"
    echo a > "$repo/lang.php"
    echo a > "$repo/gone.php"
    echo a > "$repo/keep.php"
    echo a > "$repo/other.php"
    echo a > "$repo/tests/t.php"
    echo a > "$repo/tests/a.php"
    git -C "$repo" add -A
    git -C "$repo" commit -q -m init
    echo b >> "$repo/version.php"
    echo b > "$repo/tests/ok.php"
    echo b > "$repo/.dev/ok.php"
    echo b > "$repo/classes/new file.php"
    echo b > "$repo/classes/staged.php"
    git -C "$repo" add classes/staged.php
    git -C "$repo" mv lang.php classes/renamed.php
    git -C "$repo" mv tests/a.php 'x -> tests-y.php'
    git -C "$repo" rm -q gone.php
    echo b >> "$repo/tests/t.php"
    git -C "$repo" status --porcelain=v1 -z --untracked-files=all > "$work/status"
    expect "the real status of git" \
        $'classes/new file.php\nclasses/renamed.php\nclasses/staged.php\ngone.php\nlang.php\nversion.php\nx -> tests-y.php' \
        "$(outside_release_paths "$work/status" | sort)"

    # git does not show the changes of a file that is marked skip-worktree or assume-unchanged.
    git -C "$repo" update-index --skip-worktree keep.php
    git -C "$repo" update-index --assume-unchanged other.php
    echo c >> "$repo/keep.php"
    echo c >> "$repo/other.php"
    git -C "$repo" status --porcelain=v1 -z --untracked-files=all > "$work/status"
    expect "git status does not show the hidden changes" "no" \
        "$(outside_release_paths "$work/status" | grep -q -e keep.php -e other.php && echo yes || echo no)"
fi

# hidden_release_paths: the paths of "git ls-files -v -z" that git is told to ignore (outside .dev/ and tests/).
eval "$(sed -n '/^hidden_release_paths() {/,/^}/p' "$target")"
if ! declare -F hidden_release_paths > /dev/null; then
    echo "FAIL: hidden_release_paths is not defined in $target"
    fail=$((fail + 1))
else
    nul_file "$work/files" 'H version.php' 'S keep.php' 'h other.php' 'H tests/t.php' 's tests/skip.php' 'h .dev/x.php' \
        'M unmerged.php' 'C changed.php' 'R removed.php' 's both.php' 'S name with space.php'
    expect "an invented list" $'keep.php\nother.php\nboth.php\nname with space.php' "$(hidden_release_paths "$work/files")"
    nul_file "$work/files" 'H version.php' 'H tests/t.php'
    expect "nothing is hidden" "" "$(hidden_release_paths "$work/files")"
    git -C "$repo" ls-files -v -z > "$work/files"
    expect "the real list of git" $'keep.php\nother.php' "$(hidden_release_paths "$work/files")"
fi

# stop_collecting: waits for the requests that still write their report, takes the settings and the flag away, and says
# so when it cannot.
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
    # sleep stands in for waiting; the request writing a report is done after SLEEPS_UNTIL_DONE sleeps.
    sleep() {
        echo "sleep $1" >> "$work/calls"
        sleeps=$((sleeps + 1))
        if [ "$sleeps" -ge "${SLEEPS_UNTIL_DONE:-0}" ]; then rm -f "$raw"/*.pending; fi
    }
    calls() { tr '\n' '|' < "$work/calls" | sed 's/|$//'; }

    : > "$work/calls"
    touch "$raw/ENABLED"
    collecting=1
    RUN_STATUS=0
    sleeps=0
    stop_collecting
    status=$?
    expect "ENABLED is removed" "no" "$([ -e "$raw/ENABLED" ] && echo yes || echo no)"
    expect "no request is writing: the settings are removed, then the web server is restarted" \
        "run sh -c rm -f $ini|restarted" "$(calls)"
    expect "the status is 0" "0" "$status"

    # A request that started before ENABLED went away is still writing its report: it is given time to finish.
    : > "$work/calls"
    touch "$raw/ENABLED" "$raw/web-1.pending"
    collecting=1
    sleeps=0
    SLEEPS_UNTIL_DONE=2
    stop_collecting
    expect "the requests that are writing are waited for before the restart" \
        "sleep 1|sleep 1|run sh -c rm -f $ini|restarted" "$(calls)"

    # A request that never finishes does not hold the run for ever; run.sh then fails on the leftover marker.
    : > "$work/calls"
    touch "$raw/ENABLED" "$raw/web-1.pending"
    collecting=1
    sleeps=0
    SLEEPS_UNTIL_DONE=99
    stop_collecting
    expect "the wait is limited to ten seconds, then the restart goes on" \
        "sleep 1|sleep 1|sleep 1|sleep 1|sleep 1|sleep 1|sleep 1|sleep 1|sleep 1|sleep 1|run sh -c rm -f $ini|restarted" \
        "$(calls)"
    expect "the marker is still there for the check of the run" "yes" "$([ -e "$raw/web-1.pending" ] && echo yes || echo no)"
    rm -f "$raw/web-1.pending"
    SLEEPS_UNTIL_DONE=0

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
    check_end() { # <behat status> <files>: prints "<exit status>|<output>"
        local raw="$work/rawc" out="$work/outc" file
        rm -rf "$raw" "$out"
        mkdir -p "$raw" "$out"
        for file in $2; do
            case "$file" in
                *.json) echo '{}' > "$raw/$file" ;;
                *) echo '{' > "$raw/$file" ;;
            esac
        done
        printf '%s\nbehat_status=%s\nraw="%s"\nout="%s"\n%s\necho reached-the-merge\n' \
            'set -euo pipefail' "$1" "$raw" "$out" "$block" > "$work/block.sh"
        local result code
        result="$(bash "$work/block.sh" 2>&1)"
        code=$?
        printf '%s|%s' "$code" "$result"
    }
    raw="$work/rawc"
    unfinished="a request did not finish its report (the collector leaves a .pending file until the report is complete):"
    expect "no report: the run stops and says so" \
        "1|the run left no JSON report in $raw: the collector recorded nothing" "$(check_end 0 "")"
    expect "no report and Behat failed: both are said" \
        "1|the run left no JSON report in $raw: the collector recorded nothing"$'\n'"Behat failed (exit 7)" "$(check_end 7 "")"
    expect "a report: moved to out/, the run goes on" "0|reached-the-merge" "$(check_end 0 "web-1.json")"
    expect "the report is in out/" "web-1.json" "$(ls "$work/outc")"
    expect "a request that did not finish: the run stops and names its marker" \
        "1|$unfinished $raw/web-2.pending" "$(check_end 0 "web-2.pending")"
    expect "a marker next to a good report: the run stops" \
        "1|$unfinished $raw/web-2.pending" "$(check_end 0 "web-1.json web-2.pending")"
    expect "a request that was cut off while it wrote: both files are named" \
        "1|$unfinished $raw/web-2.pending $raw/web-2.json.tmp" "$(check_end 0 "web-2.pending web-2.json.tmp")"
    expect "a half written report without its marker is named too" \
        "1|$unfinished $raw/web-2.json.tmp" "$(check_end 0 "web-1.json web-2.json.tmp")"
    expect "a good request leaves no marker: the run goes on" "0|reached-the-merge" "$(check_end 0 "web-1.json cli-2.json")"
fi

echo "run.sh pieces: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
