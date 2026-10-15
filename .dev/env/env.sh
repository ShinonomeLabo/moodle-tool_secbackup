#!/usr/bin/env bash
# Sets the variables for one dedicated development environment.
# Usage: source .dev/env/env.sh <ver> <db>   (ver: 405|500|501|502|503, db: pgsql|mariadb|mysqli)
# SECBK_SITE=<name> gives the environment a Moodle checkout, a moodle-docker folder, containers and a web port of its own
# (moodle-<ver>-<name>, port 190..), next to the plain one of the same version. .dev/ci/matrix.sh uses it for Moodle 5.0, so
# that the devconfig.sh of the matrix does not touch the plain 5.0 site, which may hold a registration made by hand.
secbk_ver="${1:?usage: env.sh <405|500|501|502|503> <pgsql|mariadb|mysqli>}"
secbk_db="${2:?usage: env.sh <405|500|501|502|503> <pgsql|mariadb|mysqli>}"

case "$secbk_ver" in
    405) secbk_branch=MOODLE_405_STABLE; secbk_php=8.3; SECBK_PLUGIN_PATH=admin/tool/secbackup ;;
    500) secbk_branch=MOODLE_500_STABLE; secbk_php=8.4; SECBK_PLUGIN_PATH=admin/tool/secbackup ;;
    501) secbk_branch=MOODLE_501_STABLE; secbk_php=8.4; SECBK_PLUGIN_PATH=public/admin/tool/secbackup ;;
    502) secbk_branch=MOODLE_502_STABLE; secbk_php=8.4; SECBK_PLUGIN_PATH=public/admin/tool/secbackup ;;
    503) secbk_branch=MOODLE_503_STABLE; secbk_php=8.4; SECBK_PLUGIN_PATH=public/admin/tool/secbackup ;;
    *) echo "unknown Moodle version: $secbk_ver" >&2; return 1 2>/dev/null || exit 1 ;;
esac
case "$secbk_db" in
    pgsql) secbk_docker_db=pgsql ;;
    mariadb) secbk_docker_db=mariadb ;;
    mysqli) secbk_docker_db=mysql ;;
    *) echo "unknown database: $secbk_db" >&2; return 1 2>/dev/null || exit 1 ;;
esac
# The lowest database version each Moodle version supports.
case "$secbk_ver-$secbk_db" in
    405-pgsql) secbk_db_version=13 ;;
    405-mariadb) secbk_db_version=10.6.7 ;;
    405-mysqli) secbk_db_version=8.0 ;;
    500-pgsql) secbk_db_version=14 ;;
    501-pgsql) secbk_db_version=15 ;;
    502-pgsql) secbk_db_version=16 ;;
    503-pgsql) secbk_db_version=17 ;;
    500-mariadb | 501-mariadb | 502-mariadb) secbk_db_version=10.11 ;;
    503-mariadb) secbk_db_version=11.4 ;;
    *-mysqli) secbk_db_version=8.4 ;;
esac
# SECBK_PHP selects another PHP version (for example the lowest one); it gets its own containers.
secbk_php="${SECBK_PHP:-$secbk_php}"

SECBK_REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# SECBK_ENV_ROOT is the directory that holds the Moodle checkouts, moodle-docker and moodle-plugin-ci. A value in the
# caller's environment wins; else the untracked file .dev/env/local.env may set it for this machine; else the default.
secbk_caller_env_root="${SECBK_ENV_ROOT:-}"
if [ -f "$SECBK_REPO/.dev/env/local.env" ]; then
    # shellcheck source=/dev/null
    source "$SECBK_REPO/.dev/env/local.env"
fi
SECBK_ENV_ROOT="${secbk_caller_env_root:-${SECBK_ENV_ROOT:-$HOME/secbackup-env}}"
unset secbk_caller_env_root
SECBK_MOODLE="$SECBK_ENV_ROOT/moodle-$secbk_ver${SECBK_SITE:+-$SECBK_SITE}"
SECBK_DOCKER="$SECBK_ENV_ROOT/moodle-docker-$secbk_ver${SECBK_SITE:+-$SECBK_SITE}"
SECBK_BRANCH="$secbk_branch"

export SECBK_REPO SECBK_ENV_ROOT SECBK_MOODLE SECBK_DOCKER SECBK_BRANCH SECBK_PLUGIN_PATH
export MOODLE_DOCKER_WWWROOT="$SECBK_MOODLE"
export MOODLE_DOCKER_DB="$secbk_docker_db"
export MOODLE_DOCKER_PHP_VERSION="$secbk_php"
export MOODLE_DOCKER_DB_VERSION="$secbk_db_version"
secbk_port_base=180
if [ -n "${SECBK_SITE:-}" ]; then secbk_port_base=190; fi
export MOODLE_DOCKER_WEB_PORT="127.0.0.1:${secbk_port_base}${secbk_ver:0:1}${secbk_ver:2:1}"
# Moodle redirects every other host name to $CFG->wwwroot, so wwwroot must be the address the scripts print.
export MOODLE_DOCKER_WEB_HOST=127.0.0.1
export MOODLE_DOCKER_BROWSER=chrome
export COMPOSE_PROJECT_NAME="secbk${secbk_ver}${secbk_db}${SECBK_PHP:+php${SECBK_PHP//./}}${SECBK_SITE:-}"
