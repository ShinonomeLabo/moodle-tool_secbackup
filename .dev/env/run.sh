#!/usr/bin/env bash
# Runs a command in the web server container, in the Moodle root.
# Usage: .dev/env/run.sh <ver> <db> <command...>
# The command's standard output, standard error and exit status come back unchanged, except that the one line
# moodle-docker writes to standard output before it starts ("Including local options from <dir>/local.yml")
# is left out, so that the output can be captured as data. Needs perl, like the commit hooks.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
shift 2
# Only the first line of standard output is looked at, and only that notice is removed; every other byte is
# passed on as it came, and standard error is not touched. pipefail makes the status of the pipeline the status
# of the command, because the filter itself ends with 0.
"$SECBK_DOCKER/bin/moodle-docker-compose" exec -T webserver "$@" |
    perl -pe 'BEGIN { $| = 1 } $_ = "" if $. == 1 && m{\AIncluding local options from .*/local\.yml\n\z}'
