#!/usr/bin/env bash
# Checks every commit in the repository history. Run it before the repository is published.
# It fails closed: a history that cannot be listed, or that is not complete, is an error.
set -u
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/rules.sh
source "$here/lib/rules.sh"

status=0
count=0
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
if ! commits="$(git rev-list --all)"; then
    echo "履歴の一覧を取れませんでした。リポジトリの中で実行してください。" >&2
    exit 1
fi
# A shallow clone lists only part of the history, so checking it would pass without checking everything.
if [ "$(git rev-parse --is-shallow-repository)" = true ]; then
    echo "履歴が浅い (shallow) ので、すべてのコミットは確かめられません。git fetch --unshallow で履歴をすべて取ってから、もう一度実行してください。" >&2
    exit 1
fi
for commit in $commits; do
    count=$((count + 1))
    if ! git log -1 --format=%B "$commit" > "$tmp"; then
        echo "  -> $commit: コミットのメッセージを読めませんでした。" >&2
        status=1
        continue
    fi
    secbackup_check_message "$tmp" || { echo "  -> $commit" >&2; status=1; }
    secbackup_check_identity "$(git log -1 --format='%an <%ae>' "$commit")" || { echo "  -> $commit" >&2; status=1; }
    secbackup_check_identity "$(git log -1 --format='%cn <%ce>' "$commit")" || { echo "  -> $commit" >&2; status=1; }
done
echo "checked ${count} commits"
exit $status
