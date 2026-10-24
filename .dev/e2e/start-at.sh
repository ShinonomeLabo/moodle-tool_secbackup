#!/usr/bin/env bash
# The time at which the ten sites of the scenario concurrent start their backups together. Development only.
# Usage: .dev/e2e/start-at.sh <the start_at of the job plan> [<now, as a UNIX time>]
#
# Prints one UNIX time: the later of the time that plan set and the time of the first step of the job plus the lead (900 s by
# default, SECBK_E2E_CONCURRENT_LEAD in seconds). The ten jobs of the matrix start together once the environment is approved,
# so their first steps differ by seconds, and the sites (3.5 to 8.4 minutes to build in the runs so far) are ready before the
# lead has passed. When the approval comes after the time that plan set, the time of plan has passed and the lead decides.
# Ends with 2 on arguments of the wrong form.
set -euo pipefail

lead="${SECBK_E2E_CONCURRENT_LEAD:-900}"
usage="usage: start-at.sh <UNIX time of plan> [<UNIX time now>]"
if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
    echo "$usage" >&2
    exit 2
fi
plan="$1"
now="${2:-$(date +%s)}"
if [[ ! "$plan" =~ ^[0-9]{10}$ ]] || [[ ! "$now" =~ ^[0-9]{10}$ ]]; then
    echo "start-at.sh: the times are UNIX times of 10 digits. $usage" >&2
    exit 2
fi
if [[ ! "$lead" =~ ^[0-9]{1,5}$ ]]; then
    echo "start-at.sh: SECBK_E2E_CONCURRENT_LEAD is a number of seconds (up to 99999)" >&2
    exit 2
fi
# Base 10 explicitly: a time or a lead with a leading zero is not octal.
start=$(( now + 10#$lead ))
if [ "$((10#$plan))" -gt "$start" ]; then
    start=$((10#$plan))
fi
echo "$start"
