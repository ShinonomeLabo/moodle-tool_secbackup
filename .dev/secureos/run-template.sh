#!/usr/bin/env bash
# Runs one Lima template end to end: creates the VM, provisions it, copies the plugin in, runs check.sh, compares the summary
# with expect.txt (assert.sh) and deletes the VM, also when a step fails or the script is interrupted. matrix.sh (all
# templates, on the Mac) and the job `secureos` of .github/workflows/ci.yml (one template on a GitHub runner) both run this
# script, so that the local run and the CI run are the same code. Development only.
# Usage: .dev/secureos/run-template.sh [-p <host port>] [-u <connection URL>] [-m standin|real|both] [-s <state>] [-k] [-r] <template>
#   -u   the connection URL of a real service. Without it the environment variable SECBK_SECUREOS_ENDPOINT is used, and when
#        that is empty too, the stand-in is used. The URL is handed to check.sh as an argument and never printed or written.
#   -m   which expectations to check: standin, real (needs a URL) or both. Default: real when there is a URL, else standin.
#   -s   the state of the access control to test: selinux (Rocky Linux, AlmaLinux), standard or confined (Debian, Ubuntu), or
#        all (default: every state that fits the machine). The job in CI runs one state in each machine.
#   -p   host port forwarded to the web server of the VM (default 18200).
#   -k   keep the VM at the end. -r   replace a VM of the same name; without it the script stops, so that it never deletes a
#        VM that someone else made.
# The output is the progress of the steps, the summary lines of check.sh (case, check, result; no detail text), the result of
# assert.sh, the time of each step and a last line "result: <template> <ok|failed|mismatch>". The exit status is 0 only for ok.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"

port=18200
url=
mode=
keep=no
replace=no
state=all
while getopts "p:u:m:s:kr" opt; do
    case "$opt" in
        p) port="$OPTARG" ;;
        u) url="$OPTARG" ;;
        m) mode="$OPTARG" ;;
        s) state="$OPTARG" ;;
        k) keep=yes ;;
        r) replace=yes ;;
        *) exit 2 ;;
    esac
done
shift $((OPTIND - 1))
template="${1:?usage: run-template.sh [-p port] [-u URL] [-m standin|real|both] [-s state] [-k] [-r] <template>}"
case "$template" in
    rocky-[0-9]* | almalinux-[0-9]* | debian-[0-9]* | ubuntu-[0-9]*.[0-9]*) ;;
    *) echo "run-template.sh: unknown template $template" >&2; exit 2 ;;
esac
case "$port" in '' | *[!0-9]*) echo "run-template.sh: the port is a number" >&2; exit 2 ;; esac
url="${url:-${SECBK_SECUREOS_ENDPOINT:-}}"
if [ -z "$mode" ]; then
    if [ -n "$url" ]; then mode=real; else mode=standin; fi
fi
case "$mode" in
    standin) ;;
    real | both) [ -n "$url" ] || { echo "run-template.sh: the mode $mode needs a connection URL" >&2; exit 2; } ;;
    *) echo "run-template.sh: the mode is standin, real or both" >&2; exit 2 ;;
esac

export SECBK_VM="secbk-$template"
. "$here/lib.sh"
case "$template:$state" in
    rocky*:all | rocky*:selinux | almalinux*:all | almalinux*:selinux) states=selinux ;;
    debian*:all | ubuntu*:all) states="standard confined" ;;
    debian*:standard | ubuntu*:standard) states=standard ;;
    debian*:confined | ubuntu*:confined) states=confined ;;
    *) echo "run-template.sh: the state $state does not fit $template" >&2; exit 2 ;;
esac
if [ "$replace" = no ] && vm_exists "$SECBK_VM"; then
    echo "run-template.sh: the machine $SECBK_VM exists; delete it, or use -r to replace it" >&2
    exit 2
fi

started="$(date +%s)"
step_started="$started"
created=no
status=failed
step() {
    local now
    now="$(date +%s)"
    echo "== $1 (the step before took $((now - step_started)) s, $((now - started)) s in all)"
    step_started="$now"
}
cleanup() {
    trap - EXIT INT TERM
    if [ "$created" = yes ] && [ "$keep" = no ]; then
        step "delete the machine"
        limactl delete -f "$SECBK_VM" > /dev/null 2>&1 || true
    fi
    step "end"
    echo "result: $template $status"
    [ "$status" = ok ] || exit 1
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "template $template, mode $mode"
step "create and start the machine"
created=yes
start_vm "$SECBK_VM" "$port" "$template" || exit 1
step "provision"
limactl shell "$SECBK_VM" sudo bash -s -- "$port" < "$here/provision.sh" || exit 1
step "copy the plugin"
"$here/sync-plugin.sh" || exit 1

status=ok
if [ "$mode" = standin ] || [ "$mode" = both ]; then
    step "check with the stand-in"
    "$here/check.sh" "$state" || status=failed
    step "assert (stand-in)"
    "$here/assert.sh" standin "$here/out/$SECBK_VM/standin/summary.txt" $states || { [ "$status" = failed ] || status=mismatch; }
fi
if [ "$mode" = real ] || [ "$mode" = both ]; then
    step "check with the real service"
    "$here/check.sh" "$state" "$url" || status=failed
    step "assert (real service)"
    "$here/assert.sh" real "$here/out/$SECBK_VM/real/summary.txt" $states || { [ "$status" = failed ] || status=mismatch; }
fi
