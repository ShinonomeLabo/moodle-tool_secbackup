#!/usr/bin/env bash
# Runs a command in the web server container, in the Moodle root.
# Usage: .dev/env/run.sh <ver> <db> <command...>
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
shift 2
"$SECBK_DOCKER/bin/moodle-docker-compose" exec -T webserver "$@"
