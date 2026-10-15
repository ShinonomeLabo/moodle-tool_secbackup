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

# --- the access control of the OS: what is found is put back -----------------------------------------------------------
# The trap is set before the first change, and it runs on an interrupt as well as on the end of the script.

apparmor() { vm_stdin sudo bash -s -- "$@" < "$(dirname "$0")/apparmor-profile.sh"; }

orig_connect=off
orig_relay=off
orig_ratelimit=
snapshot_taken=no

restore() {
    trap - EXIT INT TERM
    if [ -n "$orig_ratelimit" ]; then
        vm sudo sysctl -w "kernel.printk_ratelimit=$orig_ratelimit" > /dev/null
    fi
    if [ "$mac" = selinux ]; then
        vm sudo setsebool httpd_can_network_connect "$orig_connect"
        vm sudo setsebool httpd_can_network_relay "$orig_relay"
    elif [ "$snapshot_taken" = yes ]; then
        apparmor restore
    fi
}
trap restore EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "$mac" = selinux ]; then
    orig_connect="$(vm getsebool httpd_can_network_connect | awk '{ print $3 }')"
    orig_relay="$(vm getsebool httpd_can_network_relay | awk '{ print $3 }')"
    vm sudo setenforce 1
    [ "$(vm getenforce)" = Enforcing ] || { echo "check.sh: SELinux is not enforcing on $SECBK_VM" >&2; exit 1; }
else
    # Whether apparmor-profiles was installed, and the profile and its local rules, are kept to put back.
    apparmor snapshot
    snapshot_taken=yes
    # The kernel drops denial messages when many come in a few seconds; the case after a busy one would miss its own.
    orig_ratelimit="$(vm sysctl -n kernel.printk_ratelimit)"
    vm sudo sysctl -w kernel.printk_ratelimit=0 > /dev/null
fi
webuser="$(guest_info web_user)"
port="$(guest_info port)"
root="$(guest_info root)"
template="$(guest_info template_id)"
plugin="$(guest_info plugin_dir)"
errorlog="$(guest_info php_error_log)"
vm sudo cat /etc/secbk-secureos-info > "$out/machine.txt"

n=0
summary="$out/summary.txt"
: > "$summary"

# The connection URL is not written to the case files (they may be kept as an artifact; GitHub masks the exact secret only).
encoded="$(python3 -I -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$url")"
redact() { python3 -I -c 'import sys; t = sys.stdin.read(); [t := t.replace(a, "<endpoint>") for a in sys.argv[1:]]; sys.stdout.write(t)' "$url" "$encoded"; }

# --- measuring ---------------------------------------------------------------------------------------------------------

# The error logs of the web server, of php-fpm and of PHP itself (provision.sh sends PHP's errors to a file of the data
# directory); the sizes mark where a case starts.
marks() {
    vm_stdin sudo sh -s -- "$errorlog" << 'EOS'
for f in "$1" /var/log/httpd/error_log /var/log/php-fpm/*.log /var/log/apache2/error.log /var/log/php*-fpm.log; do
    if [ -f "$f" ]; then echo "$f $(wc -c < "$f")"; fi
done
EOS
}

# The PHP warnings, notices, deprecations and Moodle debugging messages that were logged since the marks.
new_log_lines() {
    printf '%s\n' "$1" | vm_stdin sudo sh -c 'while read -r f n; do tail -c +$((n + 1)) "$f"; done' \
        | grep -E 'PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|Debugging:'
}

# Denied network connections since the start of the case, of the process that is tested: "web" is php-fpm, "cli" is php.
# SELinux: an AVC of that command on a TCP or UDP socket. AppArmor: a socket creation of an inet family by the php-fpm
# profile (the command line is not confined, so there the command name is used). Newer kernels log class="net", older ones
# only the address family.
network_denials() {
    if [ "$mac" = selinux ]; then
        vm sudo ausearch --input-logs -m avc -ts $audit_since 2> /dev/null | grep "comm=\"$1\"" | grep -E 'tclass=(tcp|udp)_socket'
    elif [ "$1" = php-fpm ]; then
        vm sudo journalctl --no-pager --since "$journal_since" -o cat | grep 'apparmor="DENIED"' | grep 'profile="php-fpm"' \
            | grep -E 'family="inet6?"'
    else
        vm sudo journalctl --no-pager --since "$journal_since" -o cat | grep 'apparmor="DENIED"' | grep "comm=\"$1\"" \
            | grep -E 'family="inet6?"'
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

# Which advice the dns result and the connection result of a case carry: selinux (the text names setsebool), apparmor
# (the text names apparmor_parser), both or none. The body is the output of probe.php or of the command line script; the
# labels are the names of the two checks in that output. Printed as "<dns>:<connection>".
advice_kinds() {
    local body="$1" dns="$2" connection="$3" label
    for label in "$dns" "$connection"; do
        printf '%s\n' "$body" | awk -v want="$label" '
            /^\[/ { inblock = ($0 ~ ("^\\[[A-Za-z]+\\] " want ": ")); next }
            inblock && /^    / { text = text $0 }
            END {
                selinux = (text ~ /setsebool/); apparmor = (text ~ /apparmor_parser/)
                print (selinux && apparmor) ? "both" : selinux ? "selinux" : apparmor ? "apparmor" : "none"
            }'
    done | paste -sd: -
}

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

# The page of probe.php, which runs the diagnostics inside the web server. The second argument "control" asks the probe to
# raise one warning in the plugin's directory (the positive control of the warning count).
case_web() {
    local name="$1" extra="${2:-}" body file="$out/$((++n))-$1.txt" denials lines ours others
    start_case
    body="$(vm curl -s -m 180 -G -H "Host: localhost:$port" --data-urlencode "endpoint=$url" \
        ${extra:+--data-urlencode "control=1"} http://127.0.0.1/admin/tool/secbackup/.dev/secureos/probe.php)"
    sleep 1
    denials="$(network_denials php-fpm)"
    lines="$(new_log_lines "$marks_before")"
    # Only what names the plugin's directory counts; the rest (Moodle's own messages on a new PHP) is written down.
    ours="$(printf '%s\n' "$lines" | grep '/admin/tool/secbackup/')"
    others="$(printf '%s\n' "$lines" | grep -v '/admin/tool/secbackup/' | grep -c .)"
    {
        printf '%s\n' "$body"
        echo '--- all denials'
        all_denials
        echo '--- new PHP log lines naming the plugin'
        printf '%s\n' "$ours"
        echo "--- new PHP log lines of other code (not counted): $others"
    } | redact > "$file"
    field() { printf '%s\n' "$body" | sed -n "s/^\[\([a-z]*\)\] $1: .*/\1/p" | sed -n 1p; }
    dev() { printf '%s\n' "$body" | sed -n "s/^\[dev\] $1: //p" | sed -n 1p; }
    emit "$name" dns "$(field dns)"
    emit "$name" dnsproblem "$(dev dnsproblem)"
    emit "$name" connection "$(field connection)"
    emit "$name" connectionerror "$(dev connectionerror)"
    emit "$name" plugin-mac "$(field selinux)"
    emit "$name" plugin-apparmor "$(field apparmor)"
    emit "$name" advice "$(advice_kinds "$body" dns connection)"
    emit "$name" mac "$(mac_token "$(dev attr-current)")"
    emit "$name" netdenials "$(present "$denials")"
    emit "$name" phpwarnings "$(present "$(printf '%s\n' "$body" | grep -E '^(Warning|Notice|Deprecated):')$ours")"
}

# The command line, as the user of the web server (the user of cron on most sites). The second argument "control" runs a file
# that raises one warning from the plugin's directory before the script (the positive control of the warning count).
case_cli() {
    local name="$1" extra="${2:-}" body file="$out/$((++n))-$1.txt" denials context lines ours
    local prepend=()
    start_case
    if [ -n "$extra" ]; then prepend=(-d "auto_prepend_file=$plugin/.dev/secureos/control-warning.php"); fi
    body="$(vm sudo -u "$webuser" php ${prepend[@]+"${prepend[@]}"} "$plugin/cli/diagnose.php" "--endpoint=$url" 2>&1)"
    context="$(vm sudo -u "$webuser" php -r 'echo trim(@file_get_contents("/proc/self/attr/current"));')"
    sleep 1
    denials="$(network_denials php)"
    lines="$(new_log_lines "$marks_before")"
    ours="$(printf '%s\n' "$lines" | grep '/admin/tool/secbackup/')"
    {
        printf '%s\n' "$body"
        echo "--- process label: $context"
        echo '--- all denials'
        all_denials
        echo '--- new PHP log lines naming the plugin'
        printf '%s\n' "$ours"
    } | redact > "$file"
    cli_field() {
        printf '%s\n' "$body" | sed -n "s/^\[\([A-Za-z]*\)\] $1: .*/\1/p" | sed -n 1p | tr 'A-Z' 'a-z' \
            | sed 's/^ok$/pass/; s/^problem$/fail/; s/^warning$/warn/; s/^information$/info/'
    }
    emit "$name" dns "$(cli_field 'Name resolution')"
    emit "$name" connection "$(cli_field 'Connection to the service')"
    emit "$name" plugin-apparmor "$(cli_field 'AppArmor')"
    emit "$name" advice "$(advice_kinds "$body" 'Name resolution' 'Connection to the service')"
    emit "$name" mac "$(mac_token "$context")"
    emit "$name" netdenials "$(present "$denials")"
    emit "$name" phpwarnings "$(present "$(printf '%s\n' "$body" | grep -E '^(Warning|Notice|Deprecated):')$ours")"
}

# --- the states --------------------------------------------------------------------------------------------------------

# A sufficient set of local rules, found from the DENIED lines, for Moodle under the php-fpm profile: read the code, write the data directory.
# Ubuntu also needs to tell systemd that php-fpm has started, and to reach the PostgreSQL socket.
override() {
    printf '%s\n' "$root/** r," '/var/moodledata/** rwk,'
    case "$template" in
        ubuntu*) printf '%s\n' '/run/systemd/notify w,' '/run/postgresql/.s.PGSQL.* rw,' ;;
    esac
}

state_selinux() {
    local combos=("off:off:off" "relay:off:on" "connect:on:off") combo name connect relay
    for combo in "${combos[@]}"; do
        IFS=: read -r name connect relay <<< "$combo"
        vm sudo setsebool httpd_can_network_connect "$connect"
        vm sudo setsebool httpd_can_network_relay "$relay"
        case_web "selinux-web-$name"
        case_cli "selinux-cli-$name"
    done
    # The last combination leaves the network open; the control shows that a warning of the plugin's directory is counted.
    case_web selinux-web-control control
    case_cli selinux-cli-control control
}

state_standard() {
    apparmor reset
    case_web standard-web
    case_cli standard-cli
    case_web standard-web-control control
    case_cli standard-cli-control control
}

state_confined() {
    apparmor reset
    # Prints the first line of the profile as shipped, which says whether it comes in complain mode (flags).
    apparmor install > "$out/profile-header.txt"
    base=()
    while IFS= read -r line; do base+=("$line"); done < <(override)
    apparmor local "${base[@]}"
    case_web confined-web-allow
    case_cli confined-cli
    case_web confined-web-control control
    case_cli confined-cli-control control
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
    # The rules that the advice of the plugin names, taken from its English text, so that the advice is what is tested.
    advice_rules=()
    while IFS= read -r line; do
        advice_rules+=("$line")
    done < <(grep -F "\$string['diag_apparmor_advice']" "$repo/lang/en/tool_secbackup.php" | grep -oE 'network inet6? (stream|dgram),')
    if [ "${#advice_rules[@]}" -ne 4 ]; then
        echo "check.sh: the advice of the plugin does not name four network rules" >&2
        apparmor net
        exit 1
    fi
    # Only what the advice says: the rules go into the local file and the profile is loaded again; php-fpm is not restarted.
    apparmor reload "${base[@]}" "${advice_rules[@]}"
    case_web nonet-web-advice
    apparmor net
}

for s in $states; do
    "state_$s"
done
cat "$summary"
