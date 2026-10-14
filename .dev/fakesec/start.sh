#!/usr/bin/env bash
# Starts the fake backup service in the web server container of one environment (does nothing if it runs).
# Usage: .dev/fakesec/start.sh <ver> <db>
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../env/env.sh
source "$here/../env/env.sh" "${1:?ver}" "${2:?db}"
compose="$SECBK_DOCKER/bin/moodle-docker-compose"
probe='exit(@file_get_contents("http://localhost:8099/_fake/state") === false ? 1 : 0);'
if "$compose" exec -T webserver php -r "$probe"; then
    echo "fake service is running"
    exit 0
fi
"$compose" exec -d -T webserver php -S 127.0.0.1:8099 "/var/www/html/$SECBK_PLUGIN_PATH/.dev/fakesec/router.php"
for _ in $(seq 1 20); do
    if "$compose" exec -T webserver php -r "$probe"; then
        echo "fake service started"
        exit 0
    fi
    sleep 0.5
done
echo "the fake service did not start" >&2
exit 1
