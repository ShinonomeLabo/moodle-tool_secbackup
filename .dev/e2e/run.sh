#!/usr/bin/env bash
# One scenario of the staging end-to-end test: a Moodle 4.5 site on this runner backs up to the staging backup service, and
# the test endpoints of the service say what it holds. Development only, for a disposable runner (lib.sh), with
# SECBK_E2E_BASE_URL and SECBK_E2E_SECRET in the environment (sec.php; lib.sh takes the secret out of the environment of
# every other command).
# Usage: .dev/e2e/run.sh <normal|load|abort|concurrent|data-only> --name=<name> --files-gb=<GB>
#            [--start-at=<UNIX time>] [--skip=<component>[,<component>...]]... [--part=site|scenario]
#
# Every scenario first builds the site (lib.sh e2e_build_site: Moodle at the commit of moodle.json, the extra plugins of
# plugins.json but those --skip names, dummy data and <GB> GB of files of random content, checked by check-data.php).
# data-only stops there and does not reach the service. The others then get a test site from the service for the address
# of this runner (sec.php provision), trust the key of the service, register the site (enroll.php), check that the database
# of the site can be read, and run (the functions e2e_scenario_* of lib.sh):
#   normal      cli/backup.php --now. It ends with 0; the site has one run, completed with a receipt.
#   load        cli/backup.php --now in the background. When the run sends the files of the site, processes that use the CPU
#               raise the load above 2 per CPU (the limit of the plugin); the plugin stops the run (exit 1, server_busy).
#               The load stops; the service has the run as failed (server_busy) and no completed generation. After the
#               recount of the service (SECBK_E2E_RECOUNT_WAIT seconds after the stop), cli/backup.php --now ends with 0,
#               the site has two runs (failed, server_busy; completed with a receipt), and the second run did not send again
#               what the first sent (lib.sh e2e_check_resend).
#   abort       (b) cli/backup.php --now in the background; when the run has sent 10 % of its bytes in the stage files, every
#               connection to S3 is refused (iptables); the plugin fails to send, tries again and stops the run (exit 1,
#               upload_failed). The connections are allowed again; the service has the run as failed (upload_failed) and no
#               completed generation. After the recount: (a) cli/backup.php --now in the background; when that run has sent
#               10 % of its bytes in the stage files, its php is killed with kill -9 (exit 137). The service has no completed
#               generation and the run is still open. cli/backup.php without --now goes on with the same run to its end
#               (exit 0, the run completed). The site has two runs (failed, upload_failed; completed with a receipt), and the
#               run of (a) did not send again what the run of (b) sent.
#   concurrent  waits until --start-at (the same time for the ten jobs of the workflow, from start-at.sh; at most 3 hours
#               from now; a time that has passed starts at once), then as normal.
# At the end of each scenario that reaches the service, every object of the site is locked in COMPLIANCE mode and is in the
# storage class INTELLIGENT_TIERING (verdict.php). The result is in $SECBK_E2E_OUT/<name>/result.json and inspect.json
# (lib.sh); the script ends with 0 when the scenario passed, and with 2 on wrong arguments.
#
# --part=site builds the site only, without the secrets of the service in the environment (the code of Moodle, Composer and
# the extra plugins run in that part); its result says that the scenario has not run yet, and it ends with 0 once the site is
# built. --part=scenario, with the same arguments, does the rest on that site, and writes the one result of both parts.
# Without --part, one call does both.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
usage="usage: run.sh <normal|load|abort|concurrent|data-only> --name=<name> --files-gb=<GB> [--start-at=<UNIX time>]"
usage="$usage [--skip=<component>[,<component>...]]... [--part=site|scenario]"

scenario="${1:-}"
case "$scenario" in
    normal | load | abort | concurrent | data-only) shift ;;
    *) echo "$usage" >&2; exit 2 ;;
esac
name=''
gb=''
start_at=''
part=''
skips=()
while [ $# -gt 0 ]; do
    case "$1" in
        --name=*) name="${1#--name=}" ;;
        --files-gb=*) gb="${1#--files-gb=}" ;;
        --start-at=*) start_at="${1#--start-at=}" ;;
        --skip=*) skips+=("$1") ;;
        --part=*) part="${1#--part=}" ;;
        *) echo "$usage" >&2; exit 2 ;;
    esac
    shift
done
# The name is the first label of the address of the site, and the service takes 1 to 40 letters, digits and hyphens.
if [[ ! "$name" =~ ^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$ ]]; then
    echo "run.sh: --name is 1 to 40 lower case letters, digits and hyphens, without a hyphen at either end" >&2
    exit 2
fi
if [[ ! "$gb" =~ ^[0-9]+(\.[0-9]+)?$ ]] || [[ "$gb" =~ ^[0.]+$ ]]; then
    echo "run.sh: --files-gb is a number above 0 (GB of 10^9 bytes)" >&2
    exit 2
fi
if [ "$scenario" = concurrent ]; then
    if [[ ! "$start_at" =~ ^[0-9]{10}$ ]]; then
        echo "run.sh: the scenario concurrent needs --start-at=<UNIX time>" >&2
        exit 2
    fi
    # Every wait has an end: a start further away than the job can wait is a mistake.
    if [ "$start_at" -gt $(( $(date +%s) + 10800 )) ]; then
        echo "run.sh: --start-at is more than 3 hours from now" >&2
        exit 2
    fi
elif [ -n "$start_at" ]; then
    echo "run.sh: --start-at is for the scenario concurrent only" >&2
    exit 2
fi
case "$part" in
    '' | site | scenario) ;;
    *) echo "run.sh: --part is site or scenario" >&2; exit 2 ;;
esac
if [ "$scenario" = data-only ] && [ "$part" = scenario ]; then
    echo "run.sh: data-only builds the site only; it has no scenario part" >&2
    exit 2
fi
# The scenarios fill the CPUs and change the firewall: only a machine that is thrown away after the job may run them.
if [ "${GITHUB_ACTIONS:-}" != true ] && [ "${SECBK_E2E_DISPOSABLE:-}" != yes ]; then
    echo "run.sh: this runs on a disposable runner only (GITHUB_ACTIONS=true, or SECBK_E2E_DISPOSABLE=yes)" >&2
    exit 2
fi

# shellcheck source-path=SCRIPTDIR source=lib.sh
. "$here/lib.sh"

e2e_begin "$scenario" "$name" "${part:-all}"
if [ "$part" != scenario ]; then
    e2e_step "the scenario $scenario, site $name, $gb GB of files"
    e2e_check_disk "$gb"
    e2e_build_site "$name" "$gb" ${skips[@]+"${skips[@]}"}
    if [ "$part" = site ] && [ "$scenario" != data-only ]; then
        e2e_pending
    fi
fi
if [ "$part" != site ] && [ "$scenario" != data-only ]; then
    if [ "$part" = scenario ]; then
        e2e_require_site
    fi
    e2e_connect "$name"
    e2e_check_db
    case "$scenario" in
        normal) e2e_scenario_normal ;;
        load) e2e_scenario_load ;;
        abort) e2e_scenario_abort ;;
        concurrent) e2e_scenario_concurrent "$start_at" ;;
    esac
fi
e2e_finish
