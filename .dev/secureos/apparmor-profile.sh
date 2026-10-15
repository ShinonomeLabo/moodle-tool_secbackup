#!/usr/bin/env bash
# Runs in a Debian or Ubuntu VM as root: manages the php-fpm AppArmor profile of the distribution (package
# apparmor-profiles) for check.sh. Development only. Usage: sudo bash -s -- <command> [lines...] < apparmor-profile.sh
#   reset          remove the package and everything this script made, so that AppArmor is as shipped
#   install        install the package, print the profile's first line as shipped, put the profile in enforce mode
#   local <lines>  write the lines as /etc/apparmor.d/local/php-fpm, load the profile again and restart php-fpm
#   nonet          make the profile a test-only copy that has no network rule (the name-service abstraction without them)
#   net            make it include the unchanged name-service abstraction again
set -euo pipefail

profile=/etc/apparmor.d/php-fpm
localfile=/etc/apparmor.d/local/php-fpm
nonet=/etc/apparmor.d/abstractions/secbk-nameservice-nonet

restart_fpm() {
    systemctl restart $(systemctl list-unit-files 'php*-fpm.service' --no-legend | awk '{print $1}')
}

case "${1:?command}" in
    reset)
        if dpkg -s apparmor-profiles > /dev/null 2>&1; then
            DEBIAN_FRONTEND=noninteractive apt-get -y purge apparmor-profiles > /dev/null
        fi
        rm -f "$nonet" "$localfile" "$profile"
        aa-remove-unknown > /dev/null 2>&1 || true
        restart_fpm
        ;;
    install)
        DEBIAN_FRONTEND=noninteractive apt-get -y install apparmor-profiles > /dev/null
        test -f "$profile"
        grep -h -m 1 '^profile php-fpm' "$profile"
        aa-enforce "$profile" > /dev/null
        apparmor_parser -r "$profile"
        ;;
    local)
        shift
        printf '%s\n' "$@" > "$localfile"
        apparmor_parser -r "$profile"
        # php-fpm that was started under another profile (or none), or that could not start under this one, starts again.
        restart_fpm
        ;;
    nonet)
        sed -E '/^[[:space:]]*network[[:space:]]+(inet|inet6)[[:space:]]/d' /etc/apparmor.d/abstractions/nameservice > "$nonet"
        sed -i 's|<abstractions/nameservice>|<abstractions/secbk-nameservice-nonet>|' "$profile"
        apparmor_parser -r "$profile"
        ;;
    net)
        sed -i 's|<abstractions/secbk-nameservice-nonet>|<abstractions/nameservice>|' "$profile"
        apparmor_parser -r "$profile"
        ;;
    *)
        echo "unknown command: $1" >&2
        exit 1
        ;;
esac
