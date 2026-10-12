#!/usr/bin/env bash
# Runs the plugin's Behat features. Extra arguments go to behat.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
shift 2
"$here/run.sh" "$secbk_ver" "$secbk_db" php vendor/bin/behat \
    --config /var/www/behatdata/behatrun/behat/behat.yml --tags=@tool_secbackup "$@"
