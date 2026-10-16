#!/usr/bin/env bash
# Runs Infection on the security parts of the plugin in one environment (default 503 pgsql).
# One thread: Moodle's PHPUnit uses one test database (0.29 has no "threads" key in the file, only the option).
# Usage: .dev/infection/run.sh [<ver> <db>] [infection options]
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../env/env.sh
source "$repo/.dev/env/env.sh" "${1:-503}" "${2:-pgsql}"
[ "$#" -ge 2 ] && shift 2 || shift "$#"
admin="${SECBK_PLUGIN_PATH%/tool/secbackup}"
mkdir -p "$repo/.dev/infection/out"
"$repo/.dev/env/run.sh" "$secbk_ver" "$secbk_db" php "$admin/tool/phpunit/cli/util.php" --buildcomponentconfigs > /dev/null
# Infection replaces the file stream wrapper of PHP to put a mutant in place of the file. Through a user stream wrapper PHP
# decides is_writable() from the owner and the mode bits, without the exception for root, and Moodle then refuses its own
# data folder (owned by www-data). So Infection runs as www-data, the user of the web server; /tmp/infection is cleared
# first because an earlier run as root would have made it.
"$repo/.dev/env/run.sh" "$secbk_ver" "$secbk_db" rm -rf /tmp/infection
"$SECBK_DOCKER/bin/moodle-docker-compose" exec -T -u www-data webserver sh -c \
    "cd /var/www/html/$SECBK_PLUGIN_PATH && php /opt/infection/bin/infection --configuration=.dev/infection/infection.json5 --threads=1 --only-covering-test-cases --show-mutations --force-progress $*"
