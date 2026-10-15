#!/usr/bin/env bash
# Starts or removes the Selenium container of another browser next to one environment (the environment's own Selenium
# container runs Chrome). The Behat profile of the same name is made by devconfig.sh.
# Usage: .dev/env/browser.sh <ver> <db> <firefox|edge> [stop]
# Edge has no Linux build for arm64, so on Apple Silicon its image runs as linux/amd64 under emulation, slowly.
# Only the container <project>-selenium-<browser> is touched.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
browser="${3:?browser: firefox or edge}"
name="$COMPOSE_PROJECT_NAME-selenium-$browser"
case "$browser" in
    firefox) platform=() ;;
    edge) platform=(--platform linux/amd64) ;;
    *) echo "unknown browser: $browser" >&2; exit 1 ;;
esac
if [ "${4:-}" = "stop" ]; then
    docker rm -f "$name" > /dev/null 2>&1 || true
    exit 0
fi
if [ -n "$(docker ps -q --filter "name=^$name\$")" ]; then
    echo "already running: $name"
    exit 0
fi
docker rm -f "$name" > /dev/null 2>&1 || true
docker run -d --name "$name" ${platform[@]+"${platform[@]}"} --network "${COMPOSE_PROJECT_NAME}_default" \
    --network-alias "selenium-$browser" --shm-size=2g -v "$SECBK_MOODLE:/var/www/html:ro" \
    "selenium/standalone-$browser:4" > /dev/null
for _ in $(seq 1 120); do
    if docker exec "$name" curl -fs http://localhost:4444/status 2> /dev/null | grep -q '"ready": *true'; then
        echo "up: $name"
        exit 0
    fi
    sleep 2
done
echo "$name did not become ready" >&2
exit 1
