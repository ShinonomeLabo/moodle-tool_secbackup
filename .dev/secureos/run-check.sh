#!/usr/bin/env bash
# A whole backup run in a test VM, under the access control that the OS ships, as a site that runs the cron of Moodle from its
# web server: the run is started with the button of the status page and runs in php-fpm (httpd_t under SELinux, the php-fpm
# profile under AppArmor), so that its connections and its temporary files are those of the web server. Development only.
#
# Usage (on the Mac or a CI runner): [SECBK_VM=<machine>] .dev/secureos/run-check.sh run [connection URL]
# The connection URL is not used: the run goes to the fake backup service, which runs in the VM on 127.0.0.1:8099 (as root,
# outside the access control) and keeps the backup in its own storage on the same port. The argument is taken only so that
# run-template.sh calls this script as it calls check.sh. The summary goes to .dev/secureos/out/<machine>/<mode>/summary.txt,
# like that of check.sh, and assert.sh compares it with the rows run-* of expect.txt.
#
# Before the cases, as root and from the command line (which no access control confines): the developer settings that trust
# the fake service are added to config.php, the cron of Moodle is allowed from the web (cronclionly off), the time limits of
# Apache are raised for the long requests of that cron, the fake service is started, and .dev/fakesec/prepare.php registers
# the site, receives the settings and publishes the list of the official files. Two cases follow, each a new run:
#   run-denied   SELinux: httpd_can_network_connect and httpd_can_network_relay off. AppArmor: the php-fpm profile in enforce
#                mode with the local rules of check.sh and "audit deny network inet stream," (and inet6).
#   run-allowed  SELinux: httpd_can_network_connect on. AppArmor: the same profile with the local rules only.
# Each case logs in as admin with curl, presses "Back up now", requests /admin/cron.php until the run has ended (completed,
# aborted or refused) or 30 minutes have passed, and opens the status page again. Its checks:
#   start        the answer of the button (started, busy, ...: the key of the start_* string the page shows)
#   status       the status of the run in the table of the plugin
#   reason       its reason (abortcode), or none
#   page         what the status page shows: the reason whose text and advice it shows, completed for the last completed
#                backup, or none
#   mac          the label of php-fpm (the SELinux type, or the AppArmor profile and mode)
#   netdenials   present when the kernel logged a denied network connection of php-fpm during the case
#   phpwarnings  present when PHP logged a warning, notice or deprecation that names the plugin's folder during the case
# Everything the script changed is put back when it ends, also after an error.
set -uo pipefail
. "$(dirname "$0")/lib.sh"

state="${1:?usage: run-check.sh run [connection URL]}"
[ "$state" = run ] || { echo "run-check.sh: the only state is run" >&2; exit 2; }
if [ -n "${2:-}" ]; then mode=real; else mode=standin; fi
out="$repo/.dev/secureos/out/$SECBK_VM/$mode"
lang="$repo/lang/en/tool_secbackup.php"
vm true || { echo "run-check.sh: the machine $SECBK_VM is not reachable" >&2; exit 1; }
rm -rf "$out"
mkdir -p "$out"
if [ "$(vm sh -c 'test -e /sys/fs/selinux/enforce && echo yes')" = yes ]; then mac=selinux; else mac=apparmor; fi

webuser="$(guest_info web_user)"
port="$(guest_info port)"
root="$(guest_info root)"
template="$(guest_info template_id)"
plugin="$(guest_info plugin_dir)"
cli="$(guest_info admin_cli)"
errorlog="$(guest_info php_error_log)"
vm sudo cat /etc/secbk-secureos-info > "$out/machine.txt"
host="Host: localhost:$port"
jar=/tmp/secbk-run-cookies.txt

apparmor() { vm_stdin sudo bash -s -- "$@" < "$(dirname "$0")/apparmor-profile.sh"; }

# --- what is changed, and putting it back --------------------------------------------------------------------------------

orig_connect=off
orig_relay=off
snapshot_taken=no
prepared=no
# guest <on|off>: in the VM, as root, makes the site ready for the cases or puts it back (the developer settings in config.php,
# the cron of Moodle from the web, the time limits of Apache, the fake backup service).
guest() {
    vm_stdin sudo bash -s -- "$1" "$root" "$webuser" "$cli" "$plugin" << 'EOS'
set -u
what="$1" root="$2" webuser="$3" cli="$4" plugin="$5"
config="$root/config.php"
sed -i '/tool_secbackup run-check settings/,/End of tool_secbackup run-check settings/d' "$config"
rm -f /etc/httpd/conf.d/secbk-run.conf /etc/apache2/conf-enabled/secbk-run.conf /etc/apache2/conf-available/secbk-run.conf
systemctl stop secbk-fakesec > /dev/null 2>&1
if [ "$what" = on ]; then
    block='// tool_secbackup run-check settings (added by .dev/secureos/run-check.sh).
define("TOOL_SECBACKUP_DEVELOPER", ["trustedkeys" => ["PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw="],
    "allowhttp" => true, "allowprivatehosts" => true, "skipnetworkchecks" => true]);
// End of tool_secbackup run-check settings.'
    php -r '
        $text = file_get_contents($argv[1]);
        $at = strpos($text, "require_once(__DIR__ . \x27/lib/setup.php\x27);");
        $at = $at === false ? strrpos($text, "require_once") : $at;
        file_put_contents($argv[1], substr($text, 0, $at) . $argv[2] . "\n" . substr($text, $at));
    ' "$config" "$block"
    conf='Timeout 1800
ProxyTimeout 1800'
    if [ -d /etc/httpd/conf.d ]; then echo "$conf" > /etc/httpd/conf.d/secbk-run.conf; else
        echo "$conf" > /etc/apache2/conf-available/secbk-run.conf; ln -s ../conf-available/secbk-run.conf /etc/apache2/conf-enabled/
    fi
    systemd-run --quiet --unit=secbk-fakesec --collect php -d memory_limit=-1 -S 127.0.0.1:8099 \
        "$plugin/.dev/fakesec/router.php"
fi
if command -v restorecon > /dev/null; then restorecon "$config"; fi
if [ -d /etc/httpd/conf.d ]; then systemctl reload httpd; else systemctl reload apache2; fi
sudo -u "$webuser" php "$cli/cfg.php" --name=cronclionly --set="$([ "$what" = on ] && echo 0 || echo 1)" > /dev/null
EOS
}

restore() {
    trap - EXIT INT TERM
    if [ "$prepared" = yes ]; then
        guest off
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
    [ "$(vm getenforce)" = Enforcing ] || { echo "run-check.sh: SELinux is not enforcing on $SECBK_VM" >&2; exit 1; }
else
    apparmor snapshot
    snapshot_taken=yes
    vm sudo sysctl -w kernel.printk_ratelimit=0 > /dev/null
fi

# --- preparing the site, from the command line ---------------------------------------------------------------------------

prepared=yes
guest on
for _ in $(seq 1 40); do
    if vm curl -fs -o /dev/null http://127.0.0.1:8099/_fake/state; then break; fi
    sleep 0.5
done
vm sudo -u "$webuser" php "$plugin/.dev/fakesec/prepare.php" > "$out/prepare.txt" 2>&1 \
    || { cat "$out/prepare.txt" >&2; exit 1; }
cat "$out/prepare.txt"

# --- measuring -------------------------------------------------------------------------------------------------------------

summary="$out/summary.txt"
: > "$summary"
n=0
emit() { echo "$1 $2 ${3:-missing}" >> "$summary"; }
present() { if [ -n "$1" ]; then echo present; else echo none; fi; }

marks() {
    vm_stdin sudo sh -s -- "$errorlog" << 'EOS'
for f in "$1" /var/log/httpd/error_log /var/log/php-fpm/*.log /var/log/apache2/error.log /var/log/php*-fpm.log; do
    if [ -f "$f" ]; then echo "$f $(wc -c < "$f")"; fi
done
EOS
}
new_log_lines() {
    printf '%s\n' "$1" | vm_stdin sudo sh -c 'while read -r f n; do tail -c +$((n + 1)) "$f"; done' \
        | grep -E 'PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|Debugging:'
}
network_denials() {
    if [ "$mac" = selinux ]; then
        vm sudo ausearch --input-logs -m avc -ts $audit_since 2> /dev/null | grep 'comm="php-fpm"' | grep -E 'tclass=(tcp|udp)_socket'
    else
        vm sudo journalctl --no-pager --since "$journal_since" -o cat | grep 'apparmor="DENIED"' | grep 'profile="php-fpm"' \
            | grep -E 'family="inet6?"'
    fi
}

# The label of a php-fpm worker: the SELinux type, or the AppArmor profile with its mode (spaces made _).
fpm_label() {
    local label
    label="$(vm sudo sh -c 'cat "/proc/$(pgrep -n php-fpm)/attr/current"' | tr -d '\0' | tr ' ' '_')"
    case "$label" in
        *:*:*:*) echo "$label" | cut -d: -f3 ;;
        '') echo missing ;;
        *) echo "$label" ;;
    esac
}

# page_shows <html file> <start|page>: what a page of the plugin shows, from the English strings of the plugin.
#   start  the key of the start_* string of the answer of the button, or none
#   page   the reason whose text and advice are shown, completed when the last completed backup is shown, or none
page_shows() {
    python3 -I - "$1" "$lang" "$2" << 'PY'
import html, re, sys
text = open(sys.argv[1], encoding='utf-8', errors='replace').read()
page = ' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', text)).split())
source = open(sys.argv[2], encoding='utf-8').read()
strings = {k: ' '.join(re.sub(r"\\(['\\])", r'\1', v).split())
           for k, v in re.findall(r"\$string\['([a-z0-9_]+)'\] = '((?:[^'\\]|\\.)*)';", source, re.S)}
if sys.argv[3] == 'start':
    keys = [k[6:] for k in strings if k.startswith('start_') and not k.startswith('start_reason_')]
    print(next((k for k in sorted(keys) if strings['start_' + k] in page), 'none'))
else:
    codes = sorted(k[6:-7] for k in strings if k.startswith('abort_') and k.endswith('_advice'))
    shown = [c for c in codes if strings['abort_' + c] in page and strings['abort_' + c + '_advice'] in page]
    last = strings['status_last_duration'] in page and strings['status_none_completed'] not in page
    print(shown[0] if shown else ('completed' if last else 'none'))
PY
}

web() { vm curl -s -m 60 -c "$jar" -b "$jar" -H "$host" "$@"; }

run_status() {
    vm sudo -u postgres psql -At moodle -c \
        "SELECT status || ' ' || COALESCE(abortcode, 'none') FROM mdl_tool_secbackup_runs ORDER BY id DESC LIMIT 1"
}

case_run() {
    local name="$1" file="$out/$((++n))-$1.txt" token sesskey status started lines ours denials
    sleep 1.2
    journal_since="$(vm date '+%F %T')"
    audit_since="$(vm date '+%x %T')"
    marks_before="$(marks)"
    started="$(date +%s)"
    vm rm -f "$jar"
    token="$(web http://127.0.0.1/login/index.php | sed -n 's/.*name="logintoken" value="\([^"]*\)".*/\1/p' | head -n 1)"
    web -o /dev/null --data-urlencode username=admin --data-urlencode password=test --data-urlencode "logintoken=$token" \
        http://127.0.0.1/login/index.php
    web http://127.0.0.1/admin/tool/secbackup/index.php > "$out/$n-$name-before.html"
    sesskey="$(sed -n 's/.*name="sesskey" value="\([^"]*\)".*/\1/p' "$out/$n-$name-before.html" | head -n 1)"
    web -o /dev/null --data-urlencode action=start --data-urlencode "sesskey=$sesskey" \
        http://127.0.0.1/admin/tool/secbackup/index.php
    web http://127.0.0.1/admin/tool/secbackup/index.php > "$out/$n-$name-started.html"
    {
        echo "--- web cron"
        status=
        attempt=0
        while [ $(( $(date +%s) - started )) -lt 1800 ]; do
            echo "== request $((++attempt)), $(date -u +%T)"
            vm curl -s -m 1700 -H "$host" http://127.0.0.1/admin/cron.php | tail -n 60
            status="$(run_status)"
            echo "== run: $status"
            case "$status" in completed* | aborted* | refused*) break ;; esac
            sleep 10
        done
        echo "--- $(( $(date +%s) - started )) s"
    } > "$file" 2>&1
    web http://127.0.0.1/admin/tool/secbackup/index.php > "$out/$n-$name-after.html"
    sleep 1
    denials="$(network_denials)"
    lines="$(new_log_lines "$marks_before")"
    ours="$(printf '%s\n' "$lines" | grep '/admin/tool/secbackup/')"
    {
        echo '--- denials of php-fpm'
        printf '%s\n' "$denials"
        echo '--- new PHP log lines naming the plugin'
        printf '%s\n' "$ours"
    } >> "$file"
    status="$(run_status)"
    # A run that has not ended is not a result that the summary can explain: what it waits for is printed (the stage, the
    # seconds to its next attempt, the lines of the cron about the backup, the denials of the case). Nothing of it is secret:
    # the run goes to the fake service in the VM.
    case "$status" in
        completed* | aborted* | refused*) ;;
        *)
            echo "-- $name has not ended ($status)"
            vm sudo -u postgres psql -At moodle -c "SELECT stage, nextattempt - EXTRACT(EPOCH FROM NOW())::int, progress
                FROM mdl_tool_secbackup_runs ORDER BY id DESC LIMIT 1"
            grep -iE 'secbackup|backup step|exception|error|fatal|warning' "$file" | sort | uniq -c | sort -rn | head -n 30
            if [ "$mac" = selinux ]; then
                vm sudo ausearch --input-logs -m avc -ts $audit_since 2> /dev/null | grep -oE 'avc: +denied.*' \
                    | sed -E 's/ (pid|ino|dev|lport|src|dest)=[^ ]*//g' | sort | uniq -c | sort -rn | head -n 20
            else
                vm sudo journalctl --no-pager --since "$journal_since" -o cat | grep -oE 'apparmor="DENIED".*' \
                    | sed -E 's/ (pid|fsuid|ouid|requested_mask)=[^ ]*//g' | sort | uniq -c | sort -rn | head -n 20
            fi
            ;;
    esac
    emit "$name" start "$(page_shows "$out/$n-$name-started.html" start)"
    emit "$name" status "${status%% *}"
    emit "$name" reason "${status#* }"
    emit "$name" page "$(page_shows "$out/$n-$name-after.html" page)"
    emit "$name" mac "$(fpm_label)"
    emit "$name" netdenials "$(present "$denials")"
    emit "$name" phpwarnings "$(present "$ours")"
    echo "$name: $status in $(( $(date +%s) - started )) s"
}

# --- the cases -------------------------------------------------------------------------------------------------------------

# The local rules of check.sh: read the code, write the data directory; Ubuntu also needs to tell systemd that php-fpm has
# started, and to reach the PostgreSQL socket.
base=("$root/** r," '/var/moodledata/** rwk,')
case "$template" in
    ubuntu*) base+=('/run/systemd/notify w,' '/run/postgresql/.s.PGSQL.* rw,') ;;
esac

if [ "$mac" = selinux ]; then
    vm sudo setsebool httpd_can_network_connect off
    vm sudo setsebool httpd_can_network_relay off
    case_run run-denied
    vm sudo setsebool httpd_can_network_connect on
    case_run run-allowed
else
    apparmor reset
    apparmor install > "$out/profile-header.txt"
    apparmor local "${base[@]}" 'audit deny network inet stream,' 'audit deny network inet6 stream,'
    case_run run-denied
    apparmor local "${base[@]}"
    case_run run-allowed
fi
cat "$summary"
