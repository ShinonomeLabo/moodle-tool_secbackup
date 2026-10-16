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
marker="$SECBK_ENV_ROOT/installed-$COMPOSE_PROJECT_NAME"
if [ -f "$marker" ]; then
    echo "already installed: $COMPOSE_PROJECT_NAME (remove $marker after down.sh --volumes)"
    exit 0
fi

run php "$corecli/install_database.php" --agree-license --fullname="secbackup dev" \
    --shortname=secbackup --summary="tool_secbackup development" --adminpass=test --adminemail=admin@example.com
run php "$admin/tool/phpunit/cli/init.php"
# init.php of Moodle 4.5 runs util_single_run.php from its own folder (chdir), and PHP 8.1 of the moodle-php-apache image
# crashes with a segmentation fault when util_single_run.php runs there. The site is new, so init.php would only install the
# Behat site and enable it, which are the two calls below, from the Moodle root (the Composer dependencies come with the
# PHPUnit initialisation above).
run php "$admin/tool/behat/cli/util_single_run.php" --install
run php "$admin/tool/behat/cli/util_single_run.php" --enable
touch "$marker"
echo "installed: http://$MOODLE_DOCKER_WEB_PORT (admin / test)"
