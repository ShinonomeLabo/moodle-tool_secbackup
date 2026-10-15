#!/usr/bin/env bash
# The matrix: runs run-template.sh (which creates a VM, provisions it, copies the plugin in, runs check.sh with the stand-in
# URL, and with a real URL when one is given, compares the summaries with expect.txt and deletes the VM) for each Lima
# template, one or two at a time. Development only. Usage (on the Mac):
#   .dev/secureos/matrix.sh [-j <machines at once, 1 or 2>] [-u <connection URL of a real service>] [-k] [-r] [template...]
# -k keeps the VMs (at most two templates, because more than two VMs would fill the disk). -r replaces a VM that already
# has the name of a template's machine; without it the script stops, so it never deletes a VM that someone else made.
# Without templates, all eight run. -j is limited to 2, for the same reason. The exit status is 0 only when every template
# is ok.
# Results: .dev/secureos/out/matrix/<template>.log and .status, and out/secbk-<template>/{standin,real}/summary.txt.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
out="$here/out/matrix"
. "$here/lib.sh"

jobs_at_once=1
realurl=
keep=no
replace=no
while getopts "j:u:kr" opt; do
    case "$opt" in
        j) jobs_at_once="$OPTARG" ;;
        u) realurl="$OPTARG" ;;
        k) keep=yes ;;
        r) replace=yes ;;
        *) exit 2 ;;
    esac
done
shift $((OPTIND - 1))
[ "$jobs_at_once" -ge 1 ] && [ "$jobs_at_once" -le 2 ] || { echo "matrix.sh: -j is 1 or 2" >&2; exit 2; }
templates=("$@")
if [ "${#templates[@]}" -eq 0 ]; then
    templates=(rocky-9 rocky-10 almalinux-9 almalinux-10 debian-12 debian-13 ubuntu-22.04 ubuntu-24.04)
fi
if [ "$keep" = yes ] && [ "${#templates[@]}" -gt 2 ]; then
    echo "matrix.sh: -k keeps the machines, and more than two would fill the disk" >&2
    exit 2
fi
if [ "$replace" = no ]; then
    for template in "${templates[@]}"; do
        if vm_exists "secbk-$template"; then
            echo "matrix.sh: the machine secbk-$template exists; delete it, or use -r to replace it" >&2
            exit 2
        fi
    done
fi
mkdir -p "$out"

run_one() {
    local template="$1" port="$2" log="$out/$1.log" status
    local options=(-p "$port")
    [ -z "$realurl" ] || options+=(-u "$realurl" -m both)
    [ "$keep" = no ] || options+=(-k)
    [ "$replace" = no ] || options+=(-r)
    "$here/run-template.sh" "${options[@]}" "$template" > "$log" 2>&1
    status="$(sed -n 's/^result: [^ ]* //p' "$log" | tail -n 1)"
    echo "${status:-failed}" > "$out/$template.status"
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
failures=0
for template in "${templates[@]}"; do
    result="$(cat "$out/$template.status" 2> /dev/null || echo unknown)"
    echo "$template $result"
    [ "$result" = ok ] || failures=$((failures + 1))
done
[ "$failures" -eq 0 ]
