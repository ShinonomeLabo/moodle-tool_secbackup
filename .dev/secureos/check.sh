#!/usr/bin/env bash
# Runs the connection diagnostics of the plugin in a test VM, once for each state of the access control that the OS ships,
# from the web server and from the command line. Development only.
#
# Usage (on the Mac): [SECBK_VM=<machine>] .dev/secureos/check.sh <selinux|standard|confined|all> [connection URL]
#   selinux    Rocky Linux, AlmaLinux: both SELinux booleans off, relay only, connect on
#   standard   Debian, Ubuntu: AppArmor as shipped (php-fpm has no profile)
#   confined   Debian, Ubuntu: the php-fpm profile of the distribution in enforce mode, with network rules changed
#   all        the states that fit the machine
# Without a URL, a stand-in is used (see expect.txt): its address is not in the AWS ranges, so the checks show whether the
# network is open without needing the real service. Each case is written to .dev/secureos/out/<machine>/<n>-<case>.txt;
# the summary (one line per case and check, no detail text) is written to summary.txt there and printed.
# The access control is put back as it was when the script ends, also on an error.
set -uo pipefail
. "$(dirname "$0")/lib.sh"

state="${1:?usage: check.sh <selinux|standard|confined|all> [connection URL]}"
standin=https://example.com/api/lms-backup/v1
url="${2:-$standin}"
if [ "$url" = "$standin" ]; then mode=standin; else mode=real; fi
out="$repo/.dev/secureos/out/$SECBK_VM/$mode"
vm true || { echo "check.sh: the machine $SECBK_VM is not reachable" >&2; exit 1; }
rm -rf "$out"
mkdir -p "$out"

if [ "$(vm sh -c 'test -e /sys/fs/selinux/enforce && echo yes')" = yes ]; then mac=selinux; else mac=apparmor; fi
case "$state:$mac" in
    all:selinux) states="selinux" ;;
    all:apparmor) states="standard confined" ;;
    selinux:selinux | standard:apparmor | confined:apparmor) states="$state" ;;
    *) echo "check.sh: the state $state does not fit a machine with $mac" >&2; exit 1 ;;
esac
if [ "$mac" = selinux ]; then
    vm sudo setenforce 1
    [ "$(vm getenforce)" = Enforcing ] || { echo "check.sh: SELinux is not enforcing on $SECBK_VM" >&2; exit 1; }
fi
webuser="$(guest_info web_user)"
port="$(guest_info port)"
root="$(guest_info root)"
template="$(guest_info template_id)"
plugin="$root/admin/tool/secbackup"
vm test -d "$root/public" && plugin="$root/public/admin/tool/secbackup"
vm sudo cat /etc/secbk-secureos-info > "$out/machine.txt"

n=0
touched=no
summary="$out/summary.txt"
: > "$summary"

# --- the access control of the OS -------------------------------------------------------------------------------------

orig_connect=off
orig_relay=off
if [ "$mac" = selinux ]; then
    orig_connect="$(vm getsebool httpd_can_network_connect | awk '{ print $3 }')"
    orig_relay="$(vm getsebool httpd_can_network_relay | awk '{ print $3 }')"
fi

# The kernel drops denial messages when many come in a few seconds; the case after a busy one would miss its own.
orig_ratelimit=
if [ "$mac" = apparmor ]; then
    orig_ratelimit="$(vm sysctl -n kernel.printk_ratelimit)"
    vm sudo sysctl -w kernel.printk_ratelimit=0 > /dev/null
fi

restore() {
    if [ -n "$orig_ratelimit" ]; then
        vm sudo sysctl -w "kernel.printk_ratelimit=$orig_ratelimit" > /dev/null
    fi
    [ "$touched" = yes ] || return 0
    if [ "$mac" = selinux ]; then
        vm sudo setsebool httpd_can_network_connect "$orig_connect"
        vm sudo setsebool httpd_can_network_relay "$orig_relay"
    else
        apparmor reset
    fi
}
trap restore EXIT

apparmor() { vm_stdin sudo bash -s -- "$@" < "$(dirname "$0")/apparmor-profile.sh"; }

# --- measuring ---------------------------------------------------------------------------------------------------------

# The error logs of the web server and of php-fpm; the sizes mark where a case starts.
marks() {
    vm_stdin sudo sh -s << 'EOS'
for f in /var/log/httpd/error_log /var/log/php-fpm/*.log /var/log/apache2/error.log /var/log/php*-fpm.log; do
    if [ -f "$f" ]; then echo "$f $(wc -c < "$f")"; fi
done
EOS
}

new_warnings() {
    printf '%s\n' "$1" | vm_stdin sudo sh -c 'while read -r f n; do tail -c +$((n + 1)) "$f"; done' \
        | grep -E 'PHP (Warning|Notice|Deprecated|Fatal error|Parse error)'
}

# Denied network connections since the start of the case (SELinux: name_connect on a TCP socket; AppArmor: a socket
# creation, which newer kernels log with class net and older ones with the address family).
network_denials() {
    if [ "$mac" = selinux ]; then
        vm sudo ausearch --input-logs -m avc -ts $audit_since 2> /dev/null | grep 'tclass=tcp_socket'
    else
        vm sudo journalctl --no-pager --since "$journal_since" -o cat | grep 'apparmor="DENIED"' | grep -E 'class="net"|family="'
    fi
}

all_denials() {
    if [ "$mac" = selinux ]; then
        vm sudo ausearch --input-logs -m avc -ts $audit_since 2> /dev/null | grep 'type=AVC'
    else
        vm sudo journalctl --no-pager --since "$journal_since" -o cat | grep 'apparmor="DENIED"'
    fi
}

emit() { echo "$1 $2 ${3:-missing}" >> "$summary"; }

present() { if [ -n "$1" ]; then echo present; else echo none; fi; }

mac_token() {
    case "$1" in
        '') echo missing ;;
        *:*:*:*) echo "$1" | cut -d: -f3 ;;
        *) echo "$1" ;;
    esac
}

start_case() {
    sleep 1.2
    journal_since="$(vm date '+%F %T')"
    audit_since="$(vm date '+%x %T')"
    marks_before="$(marks)"
}

# The page of probe.php, which runs the diagnostics inside the web server.
case_web() {
    local name="$1" body file="$out/$((++n))-$1.txt" denials warnings
    start_case
    body="$(vm curl -s -m 180 -G -H "Host: localhost:$port" --data-urlencode "endpoint=$url" \
        http://127.0.0.1/admin/tool/secbackup/.dev/secureos/probe.php)"
    sleep 1
    denials="$(network_denials)"
    warnings="$(new_warnings "$marks_before")"
    {
        printf '%s\n' "$body"
        echo '--- all denials'
        all_denials
        echo '--- new PHP warnings'
        printf '%s\n' "$warnings"
    } > "$file"
    field() { printf '%s\n' "$body" | sed -n "s/^\[\([a-z]*\)\] $1: .*/\1/p" | head -n 1; }
    dev() { printf '%s\n' "$body" | sed -n "s/^\[dev\] $1: //p" | head -n 1; }
    emit "$name" dns "$(field dns)"
    emit "$name" dnsproblem "$(dev dnsproblem)"
    emit "$name" connection "$(field connection)"
    emit "$name" connectionerror "$(dev connectionerror | sed -E 's/^none \(HTTP ([0-9]+)\)$/http_\1/')"
    emit "$name" plugin-mac "$(field selinux)"
    emit "$name" mac "$(mac_token "$(dev attr-current)")"
    emit "$name" netdenials "$(present "$denials")"
    emit "$name" phpwarnings "$(present "$(printf '%s\n' "$body" | grep -E '^(Warning|Notice|Deprecated):')$warnings")"
}

# The command line, as the user of the web server (the user of cron on most sites).
case_cli() {
    local name="$1" body label file="$out/$((++n))-$1.txt" denials context
    start_case
    body="$(vm sudo -u "$webuser" php "$plugin/cli/diagnose.php" "--endpoint=$url" 2>&1)"
    context="$(vm sudo -u "$webuser" php -r 'echo trim(@file_get_contents("/proc/self/attr/current"));')"
    sleep 1
    denials="$(network_denials)"
    {
        printf '%s\n' "$body"
        echo "--- process label: $context"
        echo '--- all denials'
        all_denials
    } > "$file"
    cli_field() {
        printf '%s\n' "$body" | sed -n "s/^\[\([A-Za-z]*\)\] $1: .*/\1/p" | head -n 1 | tr 'A-Z' 'a-z' \
            | sed 's/^ok$/pass/; s/^problem$/fail/; s/^warning$/warn/; s/^information$/info/'
    }
    emit "$name" dns "$(cli_field 'Name resolution')"
    emit "$name" connection "$(cli_field 'Connection to the service')"
    emit "$name" mac "$(mac_token "$context")"
    emit "$name" netdenials "$(present "$denials")"
}

# --- the states --------------------------------------------------------------------------------------------------------

# The smallest local rules that Moodle itself needs under the php-fpm profile: read the code, write the data directory.
# Ubuntu also needs to tell systemd that php-fpm has started, and to reach the PostgreSQL socket.
override() {
    printf '%s\n' "$root/** r," '/var/moodledata/** rwk,'
    case "$template" in
        ubuntu*) printf '%s\n' '/run/systemd/notify w,' '/run/postgresql/.s.PGSQL.* rw,' ;;
    esac
}

state_selinux() {
    touched=yes
    local combos=("off:off:off" "relay:off:on" "connect:on:off") combo name connect relay
    for combo in "${combos[@]}"; do
        IFS=: read -r name connect relay <<< "$combo"
        vm sudo setsebool httpd_can_network_connect "$connect"
        vm sudo setsebool httpd_can_network_relay "$relay"
        case_web "selinux-web-$name"
        case_cli "selinux-cli-$name"
    done
}

state_standard() {
    touched=yes
    apparmor reset
    case_web standard-web
    case_cli standard-cli
}

state_confined() {
    touched=yes
    apparmor reset
    # Prints the first line of the profile as shipped, which says whether it comes in complain mode (flags).
    apparmor install > "$out/profile-header.txt"
    base=()
    while IFS= read -r line; do base+=("$line"); done < <(override)
    apparmor local "${base[@]}"
    case_web confined-web-allow
    case_cli confined-cli
    apparmor local "${base[@]}" 'deny network inet stream,' 'deny network inet6 stream,'
    case_web confined-web-deny
    apparmor local "${base[@]}"
    case_web confined-web-restored
    apparmor local "${base[@]}" 'audit deny network inet stream,' 'audit deny network inet6 stream,'
    case_web confined-web-denylog
    # A test-only copy of the profile without any network rule, to find the narrowest rule that lets the plugin connect.
    apparmor nonet
    apparmor local "${base[@]}"
    case_web nonet-web-none
    apparmor local "${base[@]}" 'network inet stream,' 'network inet6 stream,'
    case_web nonet-web-stream
    apparmor local "${base[@]}" 'network inet stream,' 'network inet dgram,'
    case_web nonet-web-inet
    apparmor net
}

for s in $states; do
    "state_$s"
done
cat "$summary"
