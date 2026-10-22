#!/usr/bin/env bash
# One scenario of the staging end-to-end test: a Moodle 4.5 site on this runner backs up to the staging backup service, and
# the test endpoints of the service say what it holds. Development only, for a disposable runner (lib.sh), with
# SECBK_E2E_BASE_URL and SECBK_E2E_SECRET in the environment (sec.php).
# Usage: .dev/e2e/run.sh <normal|load|abort|concurrent|data-only> --name=<name> --files-gb=<GB>
#            [--start-at=<UNIX time>] [--skip=<component>[,<component>...]]...
#
# Every scenario first builds the site (lib.sh e2e_build_site: Moodle, the extra plugins of plugins.json but those --skip
# names, dummy data and <GB> GB of files of random content, checked by check-data.php). data-only stops there and does not
# reach the service. The others then get a test site from the service for the address of this runner (sec.php provision),
# trust the key of the service and register the site (enroll.php), and run:
#   normal      cli/backup.php --now. It ends with 0; the site has one run, completed with a receipt.
#   load        cli/backup.php --now in the background. When the run sends the files of the site, processes that use the CPU
#               raise the load above 2 per CPU (the limit of the plugin); the plugin stops the run (exit 1, server_busy).
#               The load stops; the service has the run as failed (server_busy) and no completed generation. After the
#               service has listed what it holds (SECBK_E2E_RECOUNT_WAIT seconds after the stop), cli/backup.php --now ends
#               with 0, the site has two runs (failed, server_busy; completed with a receipt), and the second run did not send
#               again what the first sent (lib.sh e2e_check_resend).
#   abort       (a) cli/backup.php --now in the background; when the run has sent 10 % of its bytes in the stage files, its php
#               is killed with kill -9. The service has no completed generation and the run is still open. cli/backup.php
#               (without --now) goes on with the same run. (b) When it has sent 10 % more, every connection to S3 is
#               refused (iptables); the plugin fails to send, tries again and stops the run (exit 1, upload_failed). The
#               connections are allowed again; the service has the run as failed (upload_failed) and no completed
#               generation. After the wait of load, cli/backup.php --now ends with 0, the site has two runs (failed,
#               upload_failed; completed with a receipt), and the second did not send again what the first sent.
#   concurrent  waits until --start-at (the same time for the ten jobs of the workflow), then as normal.
# At the end of each scenario that reaches the service, every object of the site is locked in COMPLIANCE mode and is in the
# storage class INTELLIGENT_TIERING (verdict.php). The result is in $SECBK_E2E_OUT/<name>/result.json and inspect.json
# (lib.sh); the script ends with 0 when the scenario passed.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
usage="usage: run.sh <normal|load|abort|concurrent|data-only> --name=<name> --files-gb=<GB> [--start-at=<UNIX time>]"
usage="$usage [--skip=<component>[,<component>...]]..."

scenario="${1:-}"
case "$scenario" in
    normal | load | abort | concurrent | data-only) shift ;;
    *) echo "$usage" >&2; exit 2 ;;
esac
name=''
gb=''
start_at=''
skips=()
while [ $# -gt 0 ]; do
    case "$1" in
        --name=*) name="${1#--name=}" ;;
        --files-gb=*) gb="${1#--files-gb=}" ;;
        --start-at=*) start_at="${1#--start-at=}" ;;
        --skip=*) skips+=("$1") ;;
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
if [ "$scenario" = concurrent ] && [[ ! "$start_at" =~ ^[0-9]{10}$ ]]; then
    echo "run.sh: the scenario concurrent needs --start-at=<UNIX time>" >&2
    exit 2
elif [ "$scenario" != concurrent ] && [ -n "$start_at" ]; then
    echo "run.sh: --start-at is for the scenario concurrent only" >&2
    exit 2
fi
# The scenarios fill the CPUs and change the firewall: only a machine that is thrown away after the job may run them.
if [ "${GITHUB_ACTIONS:-}" != true ] && [ "${SECBK_E2E_DISPOSABLE:-}" != yes ]; then
    echo "run.sh: this runs on a disposable runner only (GITHUB_ACTIONS=true, or SECBK_E2E_DISPOSABLE=yes)" >&2
    exit 2
fi

# shellcheck source-path=SCRIPTDIR source=lib.sh
. "$here/lib.sh"

scenario_normal() {
    local since
    e2e_step "backup: cli/backup.php --now"
    since="$(date +%s)"
    e2e_backup "$e2e_logs/backup-1.log" --now
    e2e_duration backup "$since"
    if [ "$e2e_exit" -ne 0 ]; then
        e2e_fail "cli/backup.php --now ended with $e2e_exit, expected 0"
    fi
    e2e_final --runs=1
}

scenario_concurrent() {
    local now
    now="$(date +%s)"
    if [ "$now" -lt "$start_at" ]; then
        e2e_step "waiting for the start of the ten sites"
        e2e_sleep_until "$start_at" "the start of the ten sites"
        e2e_number start_late 0
    else
        echo "the site is ready $(( now - start_at )) s after the start of the ten sites"
        e2e_number start_late $(( now - start_at ))
    fi
    scenario_normal
}

# stopped_run <reason> <what was to stop it>: whether the command that just ended stopped its run with <reason> (exit 1, the
# run aborted with that reason); records a reason of the scenario when it did not.
stopped_run() {
    e2e_read_run
    if [ "$e2e_exit" -ne 1 ] || [ "$e2e_run_status" != aborted ] || [ "$e2e_run_code" != "$1" ]; then
        e2e_fail "$2 did not stop the run: cli/backup.php ended with $e2e_exit and the run is $e2e_run_status" \
            "(${e2e_run_code:-no reason}), expected 1 and aborted ($1)"
        return 1
    fi
}

# after_stop <reason> <stop time> <log of the next run>: the checks after a stopped run, the wait for the list of the
# service, the next run with --now and the checks at the end.
after_stop() {
    local reason="$1" stopped="$2" before sent since
    e2e_step "the service has the run as failed ($reason) and no completed generation"
    e2e_wait_stopped "$e2e_logs/inspect-stopped.json" "$reason"
    before="$(e2e_inspected_bytes "$e2e_logs/inspect-stopped.json")"
    e2e_step "waiting until the service has listed what it holds of the site"
    e2e_wait_recount "$stopped"
    e2e_step "backup again: cli/backup.php --now"
    since="$(date +%s)"
    e2e_backup "$3" --now
    e2e_duration backup "$since"
    if [ "$e2e_exit" -ne 0 ]; then
        e2e_fail "cli/backup.php --now after the stop ended with $e2e_exit, expected 0"
    fi
    e2e_read_run
    sent="$e2e_run_bytes"
    e2e_final --runs=2 --failed="$reason"
    e2e_check_resend "$before" "$sent" "$(e2e_inspected_bytes "$e2e_folder/inspect.json")"
}

scenario_load() {
    local since loaded
    e2e_step "backup: cli/backup.php --now, in the background"
    since="$(date +%s)"
    e2e_backup_start "$e2e_logs/backup-1.log" --now
    e2e_step "waiting until the run sends the files of the site"
    if ! e2e_wait_sending 0; then
        e2e_fail "the run did not get to the stage files, where the load is to stop it"
        e2e_wait_exit "$e2e_backup_timeout"
        e2e_final --runs=1
        return
    fi
    e2e_step "load above 2 per CPU, until the plugin stops the run"
    loaded="$(date +%s)"
    e2e_load_start
    e2e_wait_exit "$e2e_backup_timeout"
    e2e_load_stop
    e2e_duration first_run "$since"
    e2e_duration load_to_stop "$loaded"
    if ! stopped_run server_busy "the load"; then
        e2e_final --runs=1
        return
    fi
    after_stop server_busy "$(date +%s)" "$e2e_logs/backup-2.log"
}

scenario_abort() {
    local since run killed estimate blocked
    e2e_step "backup: cli/backup.php --now, in the background"
    since="$(date +%s)"
    e2e_backup_start "$e2e_logs/backup-1.log" --now
    e2e_step "(a) waiting until the run has sent 10 % of its bytes in the stage files"
    if ! e2e_wait_sending 10; then
        e2e_fail "(a) the run did not get to the point where it is killed"
        e2e_wait_exit "$e2e_backup_timeout"
        e2e_final --runs=1
        return
    fi
    run="$e2e_run_id"
    killed="$e2e_run_bytes"
    estimate="$e2e_run_estimate"
    e2e_step "(a) kill -9 of the php of cli/backup.php"
    if ! kill -9 "$e2e_pid"; then
        e2e_fail "(a) cli/backup.php ended before kill -9"
    fi
    e2e_wait_exit 60
    e2e_duration first_run "$since"
    if [ "$e2e_exit" -ne 137 ]; then
        e2e_fail "(a) cli/backup.php ended with $e2e_exit after kill -9, expected 137"
    fi
    e2e_step "(a) the service has no completed generation, and the run is open"
    e2e_inspect "$e2e_logs/inspect-killed.json" || true
    e2e_verdict "(a) after kill -9" "$e2e_logs/inspect-killed.json" --stopped=open || true
    e2e_step "(a) cli/backup.php again, without --now: the same run goes on"
    since="$(date +%s)"
    e2e_backup_start "$e2e_logs/backup-2.log"
    e2e_step "(b) waiting until the run has sent 10 % more"
    if ! e2e_wait_sending 0 $(( killed + estimate / 10 )); then
        e2e_fail "(b) the run that went on did not get to the point where S3 is blocked"
        e2e_wait_exit "$e2e_backup_timeout"
        e2e_final --runs=1
        return
    fi
    if [ "$e2e_run_id" != "$run" ]; then
        e2e_fail "(a) cli/backup.php went on with run $e2e_run_id, expected run $run"
    fi
    e2e_step "(b) the connections to S3 are refused, until the plugin stops the run"
    blocked="$(date +%s)"
    e2e_s3_block
    # The plugin tries a send three times, 15 minutes apart, before it stops the run.
    e2e_wait_exit 3600
    e2e_s3_unblock
    e2e_duration second_run "$since"
    e2e_duration s3_blocked "$blocked"
    if ! stopped_run upload_failed "(b) the refused connections to S3"; then
        e2e_final --runs=1
        return
    fi
    after_stop upload_failed "$(date +%s)" "$e2e_logs/backup-3.log"
}

e2e_begin "$scenario" "$name"
e2e_step "the scenario $scenario, site $name, $gb GB of files"
e2e_check_disk "$gb"
e2e_build_site "$name" "$gb" ${skips[@]+"${skips[@]}"}
if [ "$scenario" != data-only ]; then
    e2e_connect "$name"
    "scenario_$scenario"
fi
e2e_finish
