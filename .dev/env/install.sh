#!/usr/bin/env bash
# Installs Moodle (with the plugin) and initialises PHPUnit and Behat in one environment.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
admin="${SECBK_PLUGIN_PATH%/tool/secbackup}"   # admin or public/admin (where the admin tools live)
# The core CLI scripts stay in <root>/admin/cli on 5.1 and later; look for them instead of guessing.
if [ -f "$SECBK_MOODLE/admin/cli/install_database.php" ]; then corecli=admin/cli; else corecli=public/admin/cli; fi
run() { "$here/run.sh" "$secbk_ver" "$secbk_db" "$@"; }

run php "$corecli/install_database.php" --agree-license --fullname="secbackup dev" \
    --shortname=secbackup --summary="tool_secbackup development" --adminpass=test --adminemail=admin@example.com
run php "$admin/tool/phpunit/cli/init.php"
run php "$admin/tool/behat/cli/init.php"
echo "installed: http://$MOODLE_DOCKER_WEB_PORT (admin / test)"
