#!/usr/bin/env bash
# Runs PHPUnit, Behat and the command line scripts of one environment with coverage collection, and prints the merge.
# Usage: .dev/coverage/run.sh <ver> <db>    (SECBK_PHP selects another PHP version, as in .dev/env/env.sh)
# Exits non-zero when PHPUnit or Behat fails, or when a line of a shipped file was not executed.
# Commit first: the list of the release files is taken from HEAD, so the run refuses to start while a file outside
# .dev/ and tests/ is changed or new, or hidden from git. While it runs, the web server of the environment records every
# request, also those of a browser: leave the site alone, and do not run two environments of this checkout at the same
# time (they share .dev/coverage/out/raw). The commit of HEAD is written to out/<ver>-<db>/commit.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
name="$secbk_ver-$secbk_db${SECBK_PHP:+-php$SECBK_PHP}"
out="$repo/.dev/coverage/out/$name"
raw="$repo/.dev/coverage/out/raw"
plugin="/var/www/html/$SECBK_PLUGIN_PATH"
run() { "$repo/.dev/env/run.sh" "$secbk_ver" "$secbk_db" "$@"; }

# Prints the paths outside .dev/ and tests/ in the output of "git status --porcelain=v1 -z", which is in the file $1. An
# entry is "XY path"; for a rename or a copy the original path follows as an entry of its own. A name is never quoted.
outside_release_paths() {
    local entry candidate original
    while IFS= read -r -d '' entry; do
        original=""
        case "${entry:0:2}" in
            R? | ?R | C? | ?C) IFS= read -r -d '' original || true ;;
        esac
        for candidate in "${entry:3}" "$original"; do
            case "$candidate" in
                "" | .dev/* | tests/*) ;;
                *) printf '%s\n' "$candidate" ;;
            esac
        done
    done < "$1"
}
# Prints the paths outside .dev/ and tests/ in the output of "git ls-files -v -z", which is in the file $1, that git is told
# not to look at: a lower-case tag is assume-unchanged, S is skip-worktree. git status does not show their changes.
hidden_release_paths() {
    local entry
    while IFS= read -r -d '' entry; do
        case "${entry:0:1}" in
            [[:lower:]] | S) ;;
            *) continue ;;
        esac
        case "${entry:2}" in
            .dev/* | tests/*) ;;
            *) printf '%s\n' "${entry:2}" ;;
        esac
    done < "$1"
}
# The release list comes from HEAD (shipped_files.sh) but the files of the work tree are measured: a file that is changed
# or new and not committed would be measured without being on the list.
status_file="$(mktemp)"
files_file="$(mktemp)"
trap 'rm -f "$status_file" "$files_file"' EXIT
git -C "$repo" status --porcelain=v1 -z --untracked-files=all > "$status_file"
git -C "$repo" ls-files -v -z > "$files_file"
uncommitted="$(outside_release_paths "$status_file")"
hidden="$(hidden_release_paths "$files_file")"
if [ -n "$uncommitted" ]; then
    echo "commit first: the release list is taken from HEAD, and these paths outside .dev/ and tests/ are changed or new:" >&2
    while IFS= read -r changed_path; do
        echo "  $changed_path" >&2
    done <<< "$uncommitted"
    exit 1
fi
if [ -n "$hidden" ]; then
    echo "git is told not to look at these files outside .dev/ and tests/, so their changes would not show:" >&2
    while IFS= read -r changed_path; do
        echo "  $changed_path" >&2
    done <<< "$hidden"
    echo "Undo it with: git update-index --no-assume-unchanged --no-skip-worktree <file>" >&2
    exit 1
fi

# Apache reads its PHP settings only when it starts, so the web server container is restarted to start and to stop
# the collection. A graceful reload made the container exit (segmentation fault), and apachectl run by docker exec
# lacks the APACHE_DOCUMENT_ROOT that the container's main process has. A restart keeps the container's writable
# layer, which holds the data of the PHPUnit and Behat test sites.
ini=/usr/local/etc/php/conf.d/zz-secbk-coverage.ini
# Restarts the container and waits, for at most three minutes, until the login page answers with HTTP 200.
restart_web() {
    local url="http://$MOODLE_DOCKER_WEB_PORT/login/index.php"
    local deadline=$((SECONDS + 180))
    if ! "$SECBK_DOCKER/bin/moodle-docker-compose" restart webserver > /dev/null; then
        echo "could not restart the web server of $COMPOSE_PROJECT_NAME" >&2
        return 1
    fi
    while [ "$SECONDS" -lt "$deadline" ]; do
        if [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$url")" = 200 ]; then
            return 0
        fi
        sleep 2
    done
    echo "the web server of $COMPOSE_PROJECT_NAME did not answer HTTP 200 at $url within 180 seconds of its restart" >&2
    return 1
}
collecting=0
stop_collecting() {
    if [ "$collecting" -eq 1 ]; then
        collecting=0
        rm -f "$raw/ENABLED"
        # A request that started before ENABLED went away may still write its report (it has a .pending file): give it
        # time to finish before the restart cuts it off. One that never finishes is found by the check at the end.
        for _ in 1 2 3 4 5 6 7 8 9 10; do
            compgen -G "$raw/*.pending" > /dev/null || break
            sleep 1
        done
        if ! run sh -c "rm -f $ini"; then
            echo "the coverage settings $ini are still in the web server container of $COMPOSE_PROJECT_NAME." >&2
            echo "Start the container if it is stopped, then remove them and restart the web server:" >&2
            echo "  $repo/.dev/env/run.sh $secbk_ver $secbk_db rm -f $ini" >&2
            echo "  $SECBK_DOCKER/bin/moodle-docker-compose restart webserver" >&2
            return 1
        fi
        restart_web
    fi
}
# Whatever fails below, the collection must not stay on: the settings would outlive the run. The exit status of the
# script stays what it was.
trap 'rm -f "$status_file" "$files_file"; stop_collecting || true' EXIT

rm -rf "$out" "$raw"
mkdir -p "$out" "$raw"
git -C "$repo" rev-parse HEAD > "$out/commit"

# PHPUnit with every shipped file in the denominator.
# PHP 8.1 of the moodle-php-apache image (8.1.34 on arm64) crashes with a segmentation fault in the progress output of
# PHPUnit 9.6 after about 180 tests when pcov collects (the same run passes without pcov, and with --debug, which prints one
# line per test instead of dots). So PHP 8.1 runs with --debug; nothing else changes.
phpunit_extra=()
if [ "$secbk_php" = 8.1 ]; then phpunit_extra=(--debug); fi
SECBK_COVERAGE="$plugin/.dev/coverage/out/$name/phpunit.xml" "$repo/.dev/env/phpunit.sh" "$secbk_ver" "$secbk_db" --fail-on-warning \
    ${phpunit_extra[@]+"${phpunit_extra[@]}"}

# Behat and CLI through the collector. OPcache is off, so that the web requests count the same lines as PHPUnit does.
# The flag is set before anything is written, so that a failure half way still takes the settings away. ENABLED is made
# after the restart, so that no request is cut off by it.
collecting=1
run sh -c "printf 'auto_prepend_file=$plugin/.dev/coverage/collector.php\nopcache.enable=0\npcov.enabled=1\npcov.directory=$plugin\n' > $ini"
restart_web
touch "$raw/ENABLED"

# The declaration files run when Moodle installs the plugin: reinstall it on the development site.
if [ -f "$SECBK_MOODLE/admin/cli/upgrade.php" ]; then corecli=admin/cli; else corecli=public/admin/cli; fi
"$SECBK_DOCKER/bin/moodle-docker-compose" exec -T -e SECBK_COVERAGE_CLI=1 webserver \
    php "$corecli/uninstall_plugins.php" --plugins=tool_secbackup --run
"$SECBK_DOCKER/bin/moodle-docker-compose" exec -T -e SECBK_COVERAGE_CLI=1 webserver \
    php "$corecli/upgrade.php" --non-interactive

behat_status=0
"$repo/.dev/env/behat.sh" "$secbk_ver" "$secbk_db" || behat_status=$?
if [ -x "$repo/.dev/coverage/cli.sh" ]; then
    "$repo/.dev/coverage/cli.sh" "$secbk_ver" "$secbk_db"
fi
stop_collecting

# A report that is lost makes the merge count fewer lines, so the run must have left reports, and no request may be left
# without its report: its .pending file is still there, or a half written report.
shopt -s nullglob
unfinished=("$raw"/*.pending "$raw"/*.tmp)
collected=("$raw"/*.json)
shopt -u nullglob
if [ "${#unfinished[@]}" -gt 0 ]; then
    echo "a request did not finish its report (the collector leaves a .pending file until the report is complete): ${unfinished[*]}" >&2
    exit 1
fi
if [ "${#collected[@]}" -eq 0 ]; then
    echo "the run left no JSON report in $raw: the collector recorded nothing" >&2
    if [ "$behat_status" -ne 0 ]; then
        echo "Behat failed (exit $behat_status)" >&2
    fi
    exit 1
fi
mv "${collected[@]}" "$out"/

"$repo/.dev/coverage/shipped_files.sh" > "$out/shipped.txt"
merge_status=0
reports=("$out"/phpunit.xml "$out"/*.json)
php "$repo/.dev/coverage/merge.php" --shipped="$out/shipped.txt" --report="$out/merge.txt" "${reports[@]}" \
    || merge_status=$?
if [ "$behat_status" -ne 0 ]; then
    echo "Behat failed (exit $behat_status)" >&2
    exit 1
fi
exit "$merge_status"
