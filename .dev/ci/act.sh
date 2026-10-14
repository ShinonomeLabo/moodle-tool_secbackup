#!/usr/bin/env bash
# Runs a job of .github/workflows/ci.yml with act (GitHub Actions on the local Docker), in a clone of the committed state.
# Usage: .dev/ci/act.sh rules                                   (needs SECBACKUP_PATTERNS in the environment: a made-up list)
#        .dev/ci/act.sh test moodle:5.3 php:8.4 database:pgsql  (one combination of the matrix)
# Output: .dev/ci/out/act/<job>.log. See .dev/ci/README.md for what act cannot do.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
job="${1:?job: rules or test}"
shift
work="$repo/.dev/ci/out/act"
mkdir -p "$work"
checkout=3d3c42e5aac5ba805825da76410c181273ba90b1 # the commit of actions/checkout that ci.yml names
image=secbk-act-ubuntu:24.04

if ! docker image inspect "$image" > /dev/null 2>&1; then
    docker build --platform linux/amd64 -t "$image" "$repo/.dev/ci/act"
fi
# A clone, not the work tree: the job overwrites .dev/hooks/patterns.local and leaves files of its own in the folder.
rm -rf "${work:?}/clone"
git clone -q "$repo" "$work/clone"
cd "$work/clone"
args=(push -j "$job" --bind --pull=false --container-architecture linux/amd64 -P "ubuntu-24.04=$image"
    --local-repository "actions/checkout@$checkout=$repo/.dev/ci/act/local-checkout")
secrets=()
case "$job" in
    rules)
        : "${SECBACKUP_PATTERNS:?set SECBACKUP_PATTERNS to a made-up list of refused words, one per line}"
        secrets=(--secret "SECBACKUP_PATTERNS=$SECBACKUP_PATTERNS")
        ;;
    test)
        # act does not run the checkout of the plugin into plugin/ (path: plugin) with the local action.
        mkdir plugin
        git ls-files -z | tar --null -T - -cf - | tar -x -C plugin
        # The image has node, but the PATH that act gives the steps does not name it.
        args+=(--env "PATH=/opt/acttoolcache/node/24.19.0/x64/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin")
        for pair in "$@"; do
            args+=(--matrix "$pair")
        done
        ;;
    *) echo "unknown job: $job" >&2; exit 2 ;;
esac
act "${args[@]}" ${secrets[@]+"${secrets[@]}"} 2>&1 | tee "$work/$job.log"
