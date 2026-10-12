#!/usr/bin/env bash
# Checks every commit in the repository history. Run it before the repository is published.
set -u
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/rules.sh
source "$here/lib/rules.sh"

status=0
count=0
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
for commit in $(git rev-list --all); do
    count=$((count + 1))
    git log -1 --format=%B "$commit" > "$tmp"
    secbackup_check_message "$tmp" || { echo "  -> $commit" >&2; status=1; }
    secbackup_check_identity "$(git log -1 --format='%an <%ae>' "$commit")" || { echo "  -> $commit" >&2; status=1; }
    secbackup_check_identity "$(git log -1 --format='%cn <%ce>' "$commit")" || { echo "  -> $commit" >&2; status=1; }
done
echo "checked ${count} commits"
exit $status
