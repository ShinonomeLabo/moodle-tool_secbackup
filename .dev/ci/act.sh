#!/usr/bin/env bash
# Runs a job of .github/workflows/ci.yml with act (GitHub Actions on the local Docker), in a clone of the committed state.
# Usage: .dev/ci/act.sh rules                                   (needs SECBACKUP_PATTERNS in the environment: a made-up list)
#        .dev/ci/act.sh inventory
#        .dev/ci/act.sh test moodle:5.3 php:8.4 database:pgsql  (one combination of the matrix)
#        .dev/ci/act.sh behat browser:firefox moodle:5.3 database:pgsql   (browser: firefox or edge; moodle and database
#                                                                          name one row of the matrix, php comes with it)
# The job behat-safari needs a macOS runner and cannot run with act: SECBK_BROWSER=safari .dev/ci/run.sh runs Safari.
# SECBK_ACT_EVENT=<file> runs the pull_request event described by that JSON file instead of a push (the rules job then
# also checks the title and the description of the pull request).
# Output: .dev/ci/out/act/<job>[-<matrix values>].log. See .dev/ci/README.md for what act cannot do.
# The clone is made next to the repository (SECBK_ACT_DIR overrides), not under .dev/: the coverage collector leaves out
# every file whose path contains /.dev/, and the plugin of the test job would be under the clone.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
job="${1:?job: rules, inventory, test or behat}"
shift
work="${SECBK_ACT_DIR:-$(dirname "$repo")/secbackup-act}"
logs="$repo/.dev/ci/out/act"
logname="$job$(printf -- '-%s' "$@" | tr ':' '_')"
mkdir -p "$work" "$logs"
# The commit of actions/checkout that ci.yml names; the local action below stands in for exactly that one.
checkout="$(grep -o 'actions/checkout@[0-9a-f]\{40\}' "$repo/.github/workflows/ci.yml" | head -1 | cut -d@ -f2)"
[ -n "$checkout" ] || { echo "no pinned actions/checkout in ci.yml" >&2; exit 1; }
image=secbk-act-ubuntu:24.04

if ! docker image inspect "$image" > /dev/null 2>&1; then
    docker build --platform linux/amd64 -t "$image" "$repo/.dev/ci/act"
fi
# A clone, not the work tree: the job overwrites .dev/hooks/patterns.local and leaves files of its own in the folder.
# It is removed when the script ends, and so is the folder if nothing else is in it.
cleanup() {
    rm -rf "${work:?}/clone"
    rmdir "$work" 2> /dev/null || true
}
trap cleanup EXIT
rm -rf "${work:?}/clone"
git clone -q "$repo" "$work/clone"
cd "$work/clone"
args=("${SECBK_ACT_EVENT:+pull_request}" -j "$job" --bind --pull=false --container-architecture linux/amd64 -P "ubuntu-24.04=$image"
    --local-repository "actions/checkout@$checkout=$repo/.dev/ci/act/local-checkout")
if [ -n "${SECBK_ACT_EVENT:-}" ]; then
    args+=(-e "$SECBK_ACT_EVENT")
else
    args[0]=push
fi
secrets=()
case "$job" in
    rules)
        : "${SECBACKUP_PATTERNS:?set SECBACKUP_PATTERNS to a made-up list of refused words, one per line}"
        secrets=(--secret "SECBACKUP_PATTERNS=$SECBACKUP_PATTERNS")
        ;;
    inventory) ;;
    test | behat)
        # The local checkout action copies the files that git tracks into plugin/ (the step has path: plugin).
        # The image has node, but the PATH that act gives the steps does not name it.
        args+=(--env "PATH=/opt/acttoolcache/node/24.19.0/x64/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin")
        for pair in "$@"; do
            args+=(--matrix "$pair")
        done
        ;;
    *) echo "unknown job: $job (rules, inventory, test or behat)" >&2; exit 2 ;;
esac
act "${args[@]}" ${secrets[@]+"${secrets[@]}"} 2>&1 | tee "$logs/$logname.log"
