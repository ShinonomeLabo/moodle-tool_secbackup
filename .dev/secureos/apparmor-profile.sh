#!/usr/bin/env bash
# Runs in a Debian or Ubuntu VM as root: manages the php-fpm AppArmor profile of the distribution (package
# apparmor-profiles) for check.sh. Development only. Usage: sudo bash -s -- <command> [lines...] < apparmor-profile.sh
#   snapshot       keep whether apparmor-profiles is installed, and the profile and its local rules (once; an older
#                  snapshot of an interrupted run is the one that counts)
#   restore        put back what snapshot kept (when the package was not installed: remove it), and delete the snapshot
#   reset          remove the package and everything this script made, so that AppArmor is as shipped
#   install        install the package, print the profile's first line as shipped, put the profile in enforce mode
#   local <lines>  write the lines as /etc/apparmor.d/local/php-fpm, load the profile again and restart php-fpm
#   reload <lines> write the lines as /etc/apparmor.d/local/php-fpm and load the profile again, nothing else: php-fpm is not
#                  restarted (this is what the plugin's advice tells an administrator to do)
#   nonet          make the profile a test-only copy that has no network rule (the name-service abstraction without them)
#   net            make it include the unchanged name-service abstraction again
set -euo pipefail

profile=/etc/apparmor.d/php-fpm
localfile=/etc/apparmor.d/local/php-fpm
nonet=/etc/apparmor.d/abstractions/secbk-nameservice-nonet
snapshot=/var/lib/secbk-secureos-snapshot

restart_fpm() {
    systemctl restart $(systemctl list-unit-files 'php*-fpm.service' --no-legend | awk '{print $1}')
}

do_reset() {
    if dpkg -s apparmor-profiles > /dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get -y purge apparmor-profiles > /dev/null
    fi
    rm -f "$nonet" "$localfile" "$profile"
    aa-remove-unknown > /dev/null 2>&1 || true
    restart_fpm
}

case "${1:?command}" in
    snapshot)
        if [ ! -d "$snapshot" ]; then
            mkdir -p "$snapshot"
            if dpkg -s apparmor-profiles > /dev/null 2>&1; then
                echo yes > "$snapshot/installed"
                [ ! -f "$profile" ] || cp -p "$profile" "$snapshot/profile"
                [ ! -f "$localfile" ] || cp -p "$localfile" "$snapshot/local"
            else
                echo no > "$snapshot/installed"
            fi
        fi
        ;;
    restore)
        [ -d "$snapshot" ] || exit 0
        if [ "$(cat "$snapshot/installed")" = yes ]; then
            if ! dpkg -s apparmor-profiles > /dev/null 2>&1; then
                DEBIAN_FRONTEND=noninteractive apt-get -y install apparmor-profiles > /dev/null
            fi
            [ ! -f "$snapshot/profile" ] || cp -p "$snapshot/profile" "$profile"
            if [ -f "$snapshot/local" ]; then cp -p "$snapshot/local" "$localfile"; else rm -f "$localfile"; fi
            rm -f "$nonet"
            apparmor_parser -r "$profile"
            restart_fpm
        else
            do_reset
        fi
        rm -rf "$snapshot"
        ;;
    reset)
        do_reset
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
    reload)
        shift
        printf '%s\n' "$@" > "$localfile"
        apparmor_parser -r "$profile"
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
