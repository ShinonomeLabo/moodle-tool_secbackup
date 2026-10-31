#!/usr/bin/env bash
# shellcheck disable=SC2034 # failed is the caller's: the runs set it.
# The runs of the command line scripts that the coverage counts, for the step "Command line scripts" of the job test of
# ci.yml and for .dev/coverage/cli.sh: both source this file and call secbk_cli_runs, so that a run added here is made in
# both (.dev/coverage/tests/cli_runs.sh checks that they have no run of their own but the backup without --now, which they
# make differently). The caller defines secbk_cli_php, which runs php with its arguments where the site is (on the runner,
# or in the container of the web server) with SECBK_COVERAGE_CLI=1 set, and sets failed=0; a failed run sets failed=1.
# Every run has its own expected exit code (0: ok or help, 1: an unknown option, a wrong URL, a restore that gets no keys or
# an import that gets no line). Any other code, 255 above all, is a PHP fatal error.
# Usage: source .dev/ci/cli-runs.sh; secbk_cli_runs <folder of the scripts>
# Development only; not part of the plugin package.

# check <expected exit code> <script> [argument...]
check() {
    local expected="$1" path="$2" code=0
    shift 2
    secbk_cli_php "$path" "$@" || code=$?
    if [ "$code" != "$expected" ]; then
        echo "$(basename "$path") $* ended with exit code $code, expected $expected" >&2
        failed=1
    fi
}

# check_json <script> [argument...]: whatever PHP shows goes to the error stream, so every line that the script writes to the
# standard output is JSON, also when PHP is told to show its messages there. Nothing goes to its input. The line that
# moodle-docker-compose itself prints before the command is not the command's, and is left out.
check_json() {
    local path="$1" stdout
    # shellcheck disable=SC2016 # PHP code, in single quotes on purpose.
    local jsoncheck='while (($l = fgets(STDIN)) !== false) { json_decode($l, true, 512, JSON_THROW_ON_ERROR); }'
    shift
    stdout="$(secbk_cli_php -d display_errors=stdout -d error_reporting=-1 "$path" "$@" < /dev/null 2> /dev/null \
        | grep -v '^Including local options')" || true
    if [ -z "$stdout" ] || ! printf '%s\n' "$stdout" | php -r "$jsoncheck" > /dev/null 2>&1; then
        echo "$(basename "$path") $* wrote a line that is not JSON to the standard output" >&2
        failed=1
    fi
}

# secbk_cli_runs <folder of the scripts>
secbk_cli_runs() {
    local dir="$1"
    check 0 "$dir/diagnose.php" --help
    check 1 "$dir/diagnose.php" --no-such-option
    check 0 "$dir/diagnose.php"
    check 0 "$dir/diagnose.php" --endpoint=http://localhost:8099/api/lms-backup/v1
    check 1 "$dir/diagnose.php" --endpoint=ftp://example.org/api
    check 0 "$dir/backup.php" --help
    check 1 "$dir/backup.php" --no-such-option
    check 0 "$dir/restore.php" --help
    check 1 "$dir/restore.php" --no-such-option
    # The extraction with nothing on its input gives its public key, finds no keys and ends with the error line (1).
    check 1 "$dir/restore.php" --extract < /dev/null
    # The import without its staging folder is refused with the help (1). With nothing on its input it gets no line and ends
    # with the error line (1), before it reads the staging folder or writes anything to the site.
    check 1 "$dir/restore.php" --import
    check 1 "$dir/restore.php" --import --staging=/nonexistent/secbackup-staging < /dev/null
    check_json "$dir/restore.php" --extract
    check_json "$dir/restore.php" --import --staging=/nonexistent/secbackup-staging
}
