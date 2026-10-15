#!/usr/bin/env bash
# Compares the summary of check.sh with expect.txt and fails when a result differs. Development only; CI runs the same.
# Usage: assert.sh <real|standin> <summary.txt>
# Every line of the summary must have an expectation, and every case that is in the summary must have all its expectations.
# A row of expect.txt that names "<case>@<template>" (for example nonet-web-stream@debian13) holds for that template only
# and wins over the row of the plain case; the template is read from machine.txt next to the summary.
set -uo pipefail

mode="${1:?usage: assert.sh <real|standin> <summary.txt>}"
summary="${2:?usage: assert.sh <real|standin> <summary.txt>}"
expect="$(dirname "$0")/expect.txt"
case "$mode" in
    real) column=3 ;;
    standin) column=4 ;;
    *) echo "assert.sh: the mode is real or standin" >&2; exit 2 ;;
esac

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

# An expectation for a case that ran must have a result.
while read -r case_name check _; do
    case "$case_name" in '' | '#'*) continue ;; esac
    case_name="${case_name%@*}"
    if grep -q "^$case_name " "$summary" && ! grep -q "^$case_name $check " "$summary"; then
        echo "missing: $case_name $check has no result"
        bad=$((bad + 1))
    fi
done < "$expect"

echo "assert ($mode): $checked results checked, $bad differ"
[ "$bad" -eq 0 ]
