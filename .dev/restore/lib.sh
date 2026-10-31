#!/usr/bin/env bash
# Shared by restore.sh and compare.sh: the environments of a restore. Source it after setting ver and db.
#  - The backed-up site is the development environment <ver> <db> of the site SECBK_SOURCE_SITE (default none: the
#    environment of .dev/env/env.sh without SECBK_SITE).
#  - The restored site is the environment <ver> <target db> of the site SECBK_RESTORE_SITE (default restored). The target
#    database is the one of the backed-up site, except that MySQL is restored to MariaDB, as on the restore host.
# Every value comes from .dev/env/env.sh, in a subshell, so that the variables of one environment never leak into the other.
# ver and db come from the script that sources this file, which uses the variables that it sets.
# shellcheck disable=SC2034,SC2154
restore_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
restore_env="$restore_here/../env"
source_site="${SECBK_SOURCE_SITE:-}"
target_site="${SECBK_RESTORE_SITE:-restored}"
case "$db" in
    mysqli) target_db=mariadb ;;
    *) target_db="$db" ;;
esac

# env_value <site> <db> <variable>: the value that env.sh gives a variable for the environment of that site and database.
env_value() {
    SECBK_SITE="$1" bash -c 'source "$0" "$1" "$2" > /dev/null && printf "%s" "${!3}"' \
        "$restore_env/env.sh" "$ver" "$2" "$3"
}

# in_source <command...> and in_target <command...>: run a command in the web server container of the backed-up or the
# restored site, as root, in the Moodle root (.dev/env/run.sh: standard input and output pass unchanged).
in_source() { SECBK_SITE="$source_site" "$restore_env/run.sh" "$ver" "$db" "$@"; }
in_target() { SECBK_SITE="$target_site" "$restore_env/run.sh" "$ver" "$target_db" "$@"; }

# host_tar <arguments...>: tar on this machine, without the extended attributes that the tar of macOS adds to an archive
# (the GNU tar of the containers warns about each of them).
host_tar() {
    if tar --version 2> /dev/null | grep -q bsdtar; then
        tar --no-xattrs --no-mac-metadata "$@"
    else
        tar "$@"
    fi
}

# running <project>: whether the web server container of a compose project runs.
running() {
    [ "$(docker inspect -f '{{.State.Running}}' "$1-webserver-1" 2> /dev/null)" = true ]
}

env_root="$(env_value "$source_site" "$db" SECBK_ENV_ROOT)"
plugin="/var/www/html/$(env_value "$source_site" "$db" SECBK_PLUGIN_PATH)"
source_project="$(env_value "$source_site" "$db" COMPOSE_PROJECT_NAME)"
target_project="$(env_value "$target_site" "$target_db" COMPOSE_PROJECT_NAME)"
# The files of a restore that stay on this machine: the password of secbackup_admin and the output of each step.
target_work="$env_root/restore-$target_project"
