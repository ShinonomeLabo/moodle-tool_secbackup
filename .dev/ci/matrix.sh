#!/usr/bin/env bash
# Runs .dev/ci/run.sh for every combination of the `test` job of .github/workflows/ci.yml, one environment at a time.
# The list is read from ci.yml, so that the workflow and this script cannot drift apart.
# Usage: .dev/ci/matrix.sh [filter]    only the combinations whose text ("<ver> <db> <php>") matches the regular expression, such as 503 or mysqli
#        .dev/ci/matrix.sh --list      print the combinations and stop
# An environment that this script installs is removed with its data when its combination is done, so that the disk holds
# one site at a time; any other one (installed before, with the marker file of install.sh, or one whose install fails) is
# only stopped. SECBK_MATRIX_KEEP is a regular expression for "<ver> <db> <php>": the environments of the combinations it
# matches are stopped, not removed (SECBK_MATRIX_KEEP=. keeps all of them). Moodle 5.0 runs in its own checkout (SECBK_SITE=matrix): devconfig.sh edits config.php,
# and the plain 5.0 site may hold a registration made by hand that must stay as it is.
# One line per combination goes to .dev/ci/out/matrix.log: ok or FAILED, and the minutes. The logs of the steps are in
# .dev/ci/out/<ver>-<db>[-php<version>]/. Exits non-zero when a combination failed.
set -uo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
yml="${SECBK_CI_YML:-$repo/.github/workflows/ci.yml}"

# Prints "<ver> <db> <php>" for each row of the matrix of the job `test`.
list_combinations() {
    local text status=0
    text="$(cat "$yml")" || return 1
    php -r '
        $in_test = false;
        $found = 0;
        foreach (preg_split("/\r?\n/", $argv[1]) as $line) {
            if (preg_match("/^  (\S+):\s*$/", $line, $job)) {
                $in_test = $job[1] === "test";
            }
            if ($in_test && preg_match("/^\s*- \{moodle: \x27(\d+)\.(\d+)\x27,.* php: \x27([\d.]+)\x27, database: (\w+)/", $line, $m)) {
                echo $m[1] . "0" . $m[2] . " " . $m[4] . " " . $m[3] . "\n";
                $found++;
            }
        }
        if ($found === 0) {
            fwrite(STDERR, "no combination in the test job of " . $argv[2] . "\n");
            exit(1);
        }
    ' "$text" "$yml" || status=$?
    return "$status"
}

if [ "${1:-}" = "--list" ]; then
    list_combinations
    exit $?
fi

combos="$(list_combinations)" || exit 1
mkdir -p "$repo/.dev/ci/out"
log="$repo/.dev/ci/out/matrix.log"
: > "$log"
status=0
# The list comes on file descriptor 3: docker and Moodle scripts read standard input and would swallow the other rows.
while read -r ver db php <&3; do
    if [ -n "${1:-}" ] && ! [[ "$ver $db $php" =~ $1 ]]; then
        continue
    fi
    # The default PHP of the version is the one env.sh chooses; another one is SECBK_PHP.
    default_php="$(SECBK_PHP='' bash -c 'source "$1" "$2" "$3" && echo "$secbk_php"' _ "$repo/.dev/env/env.sh" "$ver" "$db")"
    if [ "$php" != "$default_php" ]; then export SECBK_PHP="$php"; else unset SECBK_PHP; fi
    if [ "$ver" = 500 ]; then export SECBK_SITE=matrix; else unset SECBK_SITE; fi
    project="$(bash -c 'source "$1" "$2" "$3" && echo "$COMPOSE_PROJECT_NAME"' _ "$repo/.dev/env/env.sh" "$ver" "$db")"
    env_root="$(bash -c 'source "$1" "$2" "$3" && echo "$SECBK_ENV_ROOT"' _ "$repo/.dev/env/env.sh" "$ver" "$db")"
    marker_before=0
    [ -f "$env_root/installed-$project" ] && marker_before=1
    echo "######## $ver $db $php ($project)"
    started=$SECONDS
    result=FAILED
    if "$repo/.dev/env/setup.sh" "$ver" > /dev/null \
            && "$repo/.dev/env/devconfig.sh" "$ver" > /dev/null \
            && "$repo/.dev/env/up.sh" "$ver" "$db" \
            && "$repo/.dev/env/install.sh" "$ver" "$db" \
            && "$repo/.dev/ci/run.sh" "$ver" "$db"; then
        result=ok
    else
        status=1
    fi
    printf '%-7s %s %s %s (%d min)\n' "$result" "$ver" "$db" "$php" $(((SECONDS - started + 59) / 60)) | tee -a "$log"
    # This run installed the environment only when there was no marker before it and there is one now. An install that fails
    # makes no marker, so an environment that was there before, even one without a marker, is only stopped and keeps its data.
    installed_now=0
    [ "$marker_before" -eq 0 ] && [ -f "$env_root/installed-$project" ] && installed_now=1
    if [ "$installed_now" -eq 1 ] && ! { [ -n "${SECBK_MATRIX_KEEP:-}" ] && [[ "$ver $db $php" =~ $SECBK_MATRIX_KEEP ]]; }; then
        "$repo/.dev/env/down.sh" "$ver" "$db" --volumes
    else
        "$repo/.dev/env/down.sh" "$ver" "$db"
    fi
done 3<<< "$combos"
cat "$log"
exit "$status"
