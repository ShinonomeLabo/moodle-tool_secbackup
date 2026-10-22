#!/usr/bin/env bash
# Room on the disk for the site of a job of .github/workflows/staging-e2e.yml. Development only, for a GitHub-hosted runner.
# Usage: .dev/e2e/make-room.sh [--show]
#
# Without --show: removes large tools of the runner image that the staging end-to-end test does not use (Android, .NET,
# Haskell, CodeQL, Swift), then picks the disk with the most room for the site: the root disk, or the temporary disk at /mnt
# when the runner has one. SECBK_STORAGE_DIR goes to $GITHUB_ENV for the next steps (run.sh checks there that the disk has
# room for its files). With --show: prints the room left on that disk and in memory, and the size of the site.
set -euo pipefail

if [ "${GITHUB_ACTIONS:-}" != true ] || [ "${RUNNER_ENVIRONMENT:-}" != github-hosted ]; then
    echo "make-room.sh: this runs on a GitHub-hosted runner only (it removes tools of the machine)" >&2
    exit 2
fi

# avail <path>: the bytes free on the disk of <path>.
avail() {
    df -B1 --output=avail "$1" | tail -n 1 | tr -d ' '
}

case "${1:-}" in
    --show)
        dir="${SECBK_STORAGE_DIR:-$RUNNER_TEMP}"
        df -h "$dir"
        free -m
        if [ -d "$dir" ]; then
            du -sh "$dir" 2> /dev/null || true
        fi
        exit 0
        ;;
    '') ;;
    *)
        echo "usage: make-room.sh [--show]" >&2
        exit 2
        ;;
esac

df -h / /mnt 2> /dev/null || true
before="$(avail "$RUNNER_TEMP")"
sudo rm -rf /usr/local/lib/android /usr/share/dotnet /opt/ghc /usr/local/.ghcup /opt/hostedtoolcache/CodeQL /usr/share/swift
after="$(avail "$RUNNER_TEMP")"
echo "removed the tools that the test does not use: $(( (after - before) / 1000000000 )) GB more room on the root disk"

dir="$RUNNER_TEMP/secbk-storage"
if mountpoint -q /mnt && [ "$(avail /mnt)" -gt "$after" ]; then
    sudo mkdir -p /mnt/secbk-storage
    sudo chown "$(id -u):$(id -g)" /mnt/secbk-storage
    dir=/mnt/secbk-storage
fi
mkdir -p "$dir"
echo "SECBK_STORAGE_DIR=$dir" >> "$GITHUB_ENV"
echo "the site goes to $dir:"
df -h "$dir"
free -m
