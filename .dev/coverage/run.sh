#!/usr/bin/env bash
# Runs PHPUnit, Behat and the command line scripts of one environment with coverage collection, and prints the merge.
# Usage: .dev/coverage/run.sh <ver> <db>    (SECBK_PHP selects another PHP version, as in .dev/env/env.sh)
# Exits non-zero when PHPUnit or Behat fails, or when a line of a shipped file was not executed.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
name="$secbk_ver-$secbk_db${SECBK_PHP:+-php$SECBK_PHP}"
out="$repo/.dev/coverage/out/$name"
raw="$repo/.dev/coverage/out/raw"
plugin="/var/www/html/$SECBK_PLUGIN_PATH"
run() { "$repo/.dev/env/run.sh" "$secbk_ver" "$secbk_db" "$@"; }

# Apache reads its PHP settings only when it starts, so the web server container is restarted to start and to stop
# the collection. A graceful reload made the container exit (segmentation fault), and apachectl run by docker exec
# lacks the APACHE_DOCUMENT_ROOT that the container's main process has. A restart keeps the container's writable
# layer, which holds the data of the PHPUnit and Behat test sites.
ini=/usr/local/etc/php/conf.d/zz-secbk-coverage.ini
restart_web() {
    "$SECBK_DOCKER/bin/moodle-docker-compose" restart webserver > /dev/null
    for _ in $(seq 1 60); do
        if curl -fsS -o /dev/null --max-time 10 "http://$MOODLE_DOCKER_WEB_PORT/login/index.php" 2> /dev/null; then
            return 0
        fi
        sleep 2
    done
    echo "the web server did not come back" >&2
    return 1
}
collecting=0
stop_collecting() {
    if [ "$collecting" -eq 1 ]; then
        collecting=0
        rm -f "$raw/ENABLED"
        run sh -c "rm -f $ini"
        restart_web
    fi
}
# Whatever fails below, the collection must not stay on: the settings would outlive the run. The exit status of the
# script stays what it was.
trap 'stop_collecting || true' EXIT

rm -rf "$out" "$raw"
mkdir -p "$out" "$raw"

# PHPUnit with every shipped file in the denominator.
SECBK_COVERAGE="$plugin/.dev/coverage/out/$name/phpunit.xml" "$repo/.dev/env/phpunit.sh" "$secbk_ver" "$secbk_db" --fail-on-warning

# Behat and CLI through the collector. OPcache is off, so that the web requests count the same lines as PHPUnit does.
run sh -c "printf 'auto_prepend_file=$plugin/.dev/coverage/collector.php\nopcache.enable=0\npcov.enabled=1\npcov.directory=$plugin\n' > $ini"
touch "$raw/ENABLED"
collecting=1
restart_web

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
mv "$raw"/*.json "$out"/ 2>/dev/null || true

"$repo/.dev/coverage/shipped_files.sh" > "$out/shipped.txt"
merge_status=0
php "$repo/.dev/coverage/merge.php" --shipped="$out/shipped.txt" --report="$out/merge.txt" "$out"/phpunit.xml "$out"/*.json \
    || merge_status=$?
if [ "$behat_status" -ne 0 ]; then
    echo "Behat failed (exit $behat_status)" >&2
    exit 1
fi
exit "$merge_status"
