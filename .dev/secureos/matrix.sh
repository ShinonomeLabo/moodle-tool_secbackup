#!/usr/bin/env bash
# The matrix: for each Lima template, creates a VM, provisions it, copies the plugin in, runs check.sh with the stand-in URL
# (and with a real URL when one is given), compares the summaries with expect.txt, and deletes the VM.
# Development only. Usage (on the Mac):
#   .dev/secureos/matrix.sh [-j <machines at once, 1 or 2>] [-u <connection URL of a real service>] [-k] [template...]
# -k keeps the VMs. Without templates, all eight run. More than two VMs would fill the disk, so -j is limited to 2.
# Results: .dev/secureos/out/matrix/<template>.log and .status, and out/secbk-<template>/{standin,real}/summary.txt.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
out="$here/out/matrix"

jobs_at_once=1
realurl=
keep=no
while getopts "j:u:k" opt; do
    case "$opt" in
        j) jobs_at_once="$OPTARG" ;;
        u) realurl="$OPTARG" ;;
        k) keep=yes ;;
        *) exit 2 ;;
    esac
done
shift $((OPTIND - 1))
[ "$jobs_at_once" -ge 1 ] && [ "$jobs_at_once" -le 2 ] || { echo "matrix.sh: -j is 1 or 2" >&2; exit 2; }
templates=("$@")
if [ "${#templates[@]}" -eq 0 ]; then
    templates=(rocky-9 rocky-10 almalinux-9 almalinux-10 debian-12 debian-13 ubuntu-22.04 ubuntu-24.04)
fi
mkdir -p "$out"

# A host port of its own: from 18200 by the template's place in the list, and on past any port that is in use.
free_port() {
    local p=$((18200 + $1))
    while [ -n "$(lsof -nP -iTCP:"$p" -sTCP:LISTEN -t 2> /dev/null)" ]; do
        p=$((p + 20))
    done
    echo "$p"
}

# Creates and starts the VM. A machine sometimes does not come up in time (the image boots slowly while another VM starts),
# so a failed start is tried once more with a new machine.
start_vm() {
    local name="$1" port="$2" template="$3" attempt
    for attempt in 1 2; do
        limactl delete -f "$name" > /dev/null 2>&1 || true
        if limactl create --tty=false --name="$name" --cpus=2 --memory=4 --disk=20 --mount-none \
            --set ".portForwards=[{\"guestPort\":80,\"hostPort\":$port,\"hostIP\":\"127.0.0.1\"}]" "template:$template" \
            && limactl start --tty=false "$name"; then
            return 0
        fi
        echo "start of $name failed (attempt $attempt)"
    done
    return 1
}

run_one() {
    local template="$1" port="$2" name="secbk-$1" log="$out/$1.log"
    exec > "$log" 2>&1
    export SECBK_VM="$name"
    echo "== $template on port $port"
    status=failed
    if start_vm "$name" "$port" "$template" \
        && limactl shell "$name" sudo bash -s -- "$port" < "$here/provision.sh" \
        && "$here/sync-plugin.sh" \
        && "$here/check.sh" all > /dev/null; then
        status=ok
        "$here/assert.sh" standin "$here/out/$name/standin/summary.txt" || status=mismatch
        if [ -n "$realurl" ]; then
            "$here/check.sh" all "$realurl" > /dev/null || status=failed
            "$here/assert.sh" real "$here/out/$name/real/summary.txt" || status=mismatch
        fi
    fi
    echo "$status" > "$out/$template.status"
    if [ "$keep" = no ]; then
        limactl delete -f "$name" > /dev/null 2>&1 || true
    fi
}

running=()
index=0
start_time="$(date +%s)"
for template in "${templates[@]}"; do
    # Wait until fewer than the allowed number of VMs are running.
    while :; do
        alive=()
        for pid in ${running[@]+"${running[@]}"}; do
            if kill -0 "$pid" 2> /dev/null; then alive+=("$pid"); fi
        done
        running=(${alive[@]+"${alive[@]}"})
        [ "${#running[@]}" -lt "$jobs_at_once" ] && break
        sleep 10
    done
    rm -f "$out/$template.status"
    run_one "$template" "$(free_port "$index")" &
    index=$((index + 1))
    running+=("$!")
done
wait

echo "matrix finished in $(( ($(date +%s) - start_time) / 60 )) minutes"
for template in "${templates[@]}"; do
    echo "$template $(cat "$out/$template.status" 2> /dev/null || echo unknown)"
done
