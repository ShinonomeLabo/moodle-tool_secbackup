#!/usr/bin/env bash
# Runs the plugin's Behat features. Extra arguments go to behat.
# SECBK_BROWSER=chrome|firefox|edge|safari selects the browser (default chrome, the Selenium container of the environment):
#   firefox, edge  start their own Selenium container next to the environment (.dev/env/browser.sh) and use the profile of
#                  the same name. Edge runs emulated on Apple Silicon and is slow, so its time limits are 8 times longer.
#   safari         runs Safari on this Mac through safaridriver, which opens an automation window on the desktop. The Behat
#                  site is then reached as http://localhost:<port of the environment> (the Mac cannot resolve http://webserver),
#                  and two relays (relay.php) let the container reach safaridriver and that address. Safari must be allowed to be
#                  automated once (sudo safaridriver --enable); this script changes no setting of Safari or of the system.
# It enables the Behat test site first. That rebuilds the list of features and steps, so a feature or a step file added since
# the site was initialised is not skipped without a word. When the site was initialised for other versions of Moodle or of
# its plugins, it must be initialised again: the script stops and shows the command.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
shift 2
admin="${SECBK_PLUGIN_PATH%/tool/secbackup}"
browser="${SECBK_BROWSER:-chrome}"
profile=()
pids=()
wwwroot_file="$SECBK_MOODLE/.secbk-behat-wwwroot"

enable_site() {
    local status=0 output
    output="$("$here/run.sh" "$secbk_ver" "$secbk_db" php "$admin/tool/behat/cli/util_single_run.php" --enable 2>&1)" || status=$?
    # 253 is BEHAT_EXITCODE_REINSTALL of Moodle.
    if [ "$status" -eq 253 ]; then
        echo "The Behat test site was initialised for other versions of Moodle or of its plugins. Initialise it again:" >&2
        echo "  $here/run.sh $secbk_ver $secbk_db php $admin/tool/behat/cli/init.php --disable-composer" >&2
        return 1
    elif [ "$status" -ne 0 ]; then
        echo "$output" >&2
        echo "util_single_run.php --enable failed (exit $status)" >&2
        return 1
    fi
}
cleanup() {
    local pid
    for pid in ${pids[@]+"${pids[@]}"}; do
        kill "$pid" 2> /dev/null || true
    done
    if [ "$browser" = safari ]; then
        "$here/run.sh" "$secbk_ver" "$secbk_db" sh -c 'kill "$(cat /tmp/secbk-relay.pid)"; rm -f /tmp/secbk-relay.pid' > /dev/null 2>&1 || true
        # Back to the address of the container network, for the next run with another browser.
        rm -f "$wwwroot_file"
        enable_site || true
    fi
}
trap cleanup EXIT

case "$browser" in
    chrome) ;;
    firefox)
        "$here/browser.sh" "$secbk_ver" "$secbk_db" firefox
        profile=(-p firefox)
        ;;
    edge)
        "$here/browser.sh" "$secbk_ver" "$secbk_db" edge
        profile=(-p edge)
        export SECBK_EXEC_ENV="${SECBK_EXEC_ENV:-} MOODLE_DOCKER_TIMEOUT_FACTOR=8"
        ;;
    safari)
        if ! curl -fs --max-time 3 http://localhost:4445/status > /dev/null; then
            safaridriver -p 4445 > /dev/null 2>&1 &
            pids+=("$!")
        fi
        php "$here/relay.php" 4446 4445 localhost &
        pids+=("$!")
        webport="${MOODLE_DOCKER_WEB_PORT##*:}"
        "$SECBK_DOCKER/bin/moodle-docker-compose" exec -d -T webserver sh -c \
            "echo \$\$ > /tmp/secbk-relay.pid; exec php /var/www/html/$SECBK_PLUGIN_PATH/.dev/env/relay.php $webport 80"
        for _ in $(seq 1 20); do
            if curl -fs --max-time 3 http://localhost:4446/status > /dev/null; then break; fi
            sleep 0.5
        done
        # Written before the site is enabled: config.php reads it, and behat.yml gets the address from there.
        printf 'http://localhost:%s\n' "$webport" > "$wwwroot_file"
        profile=(-p safari)
        ;;
    *)
        echo "unknown browser: $browser (chrome, firefox, edge or safari)" >&2
        exit 1
        ;;
esac

enable_site
"$SECBK_REPO/.dev/fakesec/start.sh" "$secbk_ver" "$secbk_db"
"$here/run.sh" "$secbk_ver" "$secbk_db" php vendor/bin/behat \
    --config /var/www/behatdata/behatrun/behat/behat.yml --tags=@tool_secbackup ${profile[@]+"${profile[@]}"} "$@"
