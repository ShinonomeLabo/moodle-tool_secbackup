#!/usr/bin/env bash
# Runs the diagnostics in the SELinux VM with the network booleans off, relay only and connect on,
# from the web server (httpd_t) and from the command line. Results go to .dev/selinux/out/.
# Development only. Usage (on the Mac): .dev/selinux/check.sh <connection URL of a real service>
set -uo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
out="$repo/.dev/selinux/out"
mkdir -p "$out"
url="${1:?usage: check.sh <connection URL>}"
# ausearch reads standard input when it is a pipe; --input-logs makes it read the audit log instead.
vm() { limactl shell secbk-selinux "$@" < /dev/null; }
probe() { curl -s "http://localhost:18080/admin/tool/secbackup/.dev/selinux/probe.php?endpoint=$url"; }

vm sudo setsebool httpd_can_network_connect off
vm sudo setsebool httpd_can_network_relay off
probe > "$out/1-web-off.txt"
vm sudo ausearch --input-logs -m avc -ts recent > "$out/1-avc.txt" 2>&1 || true
vm sudo -u apache php /var/www/html/moodle/admin/tool/secbackup/cli/diagnose.php "--endpoint=$url" > "$out/1-cli-off.txt" 2>&1

vm sudo setsebool httpd_can_network_relay on
probe > "$out/2-web-relay.txt"

vm sudo setsebool httpd_can_network_relay off
vm sudo setsebool httpd_can_network_connect on
probe > "$out/3-web-connect.txt"

vm sudo setsebool httpd_can_network_connect off
vm sudo grep -c 'PHP Warning' /var/log/httpd/error_log > "$out/php-warnings.txt" 2>&1 || true
echo "results in $out"
