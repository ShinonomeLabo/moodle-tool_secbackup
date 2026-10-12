#!/usr/bin/env bash
# Stops the containers of one environment and keeps the installed data (the database, moodledata,
# PHPUnit and Behat data); up.sh starts them again. With --volumes it removes the containers
# and all of that data.
# Usage: .dev/env/down.sh <ver> <db> [--volumes]
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
if [ "${3:-}" = "--volumes" ]; then
    "$SECBK_DOCKER/bin/moodle-docker-compose" down --volumes
else
    "$SECBK_DOCKER/bin/moodle-docker-compose" stop
fi
