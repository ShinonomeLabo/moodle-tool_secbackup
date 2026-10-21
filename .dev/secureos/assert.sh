#!/usr/bin/env bash
# Compares the summary of check.sh with expect.txt and fails when a result differs. Development only; CI runs the same.
# Usage: assert.sh <real|standin> <summary.txt> <state>...      (the states that ran: selinux, standard, confined, run)
# Fails when the summary is missing, unreadable or empty, when nothing was checked, when a line has no expectation, when a
# result differs, and when a case of a named state (every case of expect.txt whose name starts with selinux-, standard-,
# confined-/nonet- or run-) or a check of a case that ran has no result.
# A row of expect.txt that names "<case>@<template>" (for example nonet-web-stream@debian13) holds for that template only
# and wins over the row of the plain case; the template is read from machine.txt next to the summary.
set -uo pipefail

usage="usage: assert.sh <real|standin> <summary.txt> <state>..."
mode="${1:?$usage}"
summary="${2:?$usage}"
shift 2
[ "$#" -ge 1 ] || { echo "assert.sh: $usage" >&2; exit 2; }
expect="$(dirname "$0")/expect.txt"
case "$mode" in
    real) column=3 ;;
    standin) column=4 ;;
    *) echo "assert.sh: the mode is real or standin" >&2; exit 2 ;;
esac
if [ ! -r "$summary" ] || [ ! -s "$summary" ]; then
    echo "assert.sh: the summary is missing or empty, nothing was checked" >&2
    exit 1
fi
template="$(sed -n 's/^template_id="\(.*\)"$/\1/p' "$(dirname "$summary")/machine.txt" 2> /dev/null)"

checked=0
bad=0
while read -r case_name check result; do
    [ -n "$case_name" ] || continue
    wanted="$(awk -v c="$case_name" -v t="$template" -v k="$check" -v col="$column" '
        $2 == k && $1 == c "@" t { specific = $col }
        $2 == k && $1 == c { general = $col }
        END { print (specific != "" ? specific : general) }' "$expect")"
    checked=$((checked + 1))
    if [ -z "$wanted" ]; then
        echo "no expectation: $case_name $check (result $result)"
        bad=$((bad + 1))
    elif [ "$wanted" != "$result" ]; then
        echo "differs: $case_name $check is $result, expected $wanted"
        bad=$((bad + 1))
    fi
done < "$summary"

# Every case of the states that ran, and every check of a case that ran, must have a result.
prefixes=
for state in "$@"; do
    case "$state" in
        selinux) prefixes="$prefixes selinux-" ;;
        standard) prefixes="$prefixes standard-" ;;
        confined) prefixes="$prefixes confined- nonet-" ;;
        run) prefixes="$prefixes run-" ;;
        *) echo "assert.sh: unknown state $state" >&2; exit 2 ;;
    esac
done
while read -r case_name check _; do
    case "$case_name" in '' | '#'*) continue ;; esac
    case_name="${case_name%@*}"
    wanted_case=no
    for prefix in $prefixes; do
        case "$case_name" in "$prefix"*) wanted_case=yes ;; esac
    done
    if { [ "$wanted_case" = yes ] || grep -q "^$case_name " "$summary"; } && ! grep -q "^$case_name $check " "$summary"; then
        echo "missing: $case_name $check has no result"
        bad=$((bad + 1))
    fi
done < "$expect"

if [ "$checked" -eq 0 ]; then
    echo "assert.sh: nothing was checked" >&2
    exit 1
fi
echo "assert ($mode): $checked results checked, $bad differ"
[ "$bad" -eq 0 ]
