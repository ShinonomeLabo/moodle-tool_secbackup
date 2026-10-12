#!/usr/bin/env bash
# Starts the containers of one environment and waits for the database.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
"$SECBK_DOCKER/bin/moodle-docker-compose" up -d
"$SECBK_DOCKER/bin/moodle-docker-wait-for-db"
echo "up: http://$MOODLE_DOCKER_WEB_PORT ($COMPOSE_PROJECT_NAME)"
