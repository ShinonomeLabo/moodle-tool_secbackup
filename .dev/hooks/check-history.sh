#!/usr/bin/env bash
# Checks every commit in the repository history. Run it before the repository is published.
# It fails closed: a history that cannot be listed, or that is not complete, is an error.
# git 2.15 or later is needed: it tells with --is-shallow-repository whether the history is complete.
set -u
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/rules.sh
source "$here/lib/rules.sh"
# The temporary file of secbackup_check_commit must not stay behind when a signal stops this script.
trap secbackup_remove_message_file EXIT

status=0
count=0
if ! commits="$(git rev-list --all)"; then
    echo "履歴の一覧を取れませんでした。リポジトリの中で実行してください。" >&2
    exit 1
fi
# A shallow clone lists only part of the history, so checking it would pass without checking everything. Only a
# plain "false" lets the check go on: a failure, or the option printed back by a git before 2.15, stops it too.
if [ "$(git rev-parse --is-shallow-repository)" != false ]; then
    echo "履歴が浅い (shallow) か、浅いかどうかを確かめられませんでした。すべてのコミットは確かめられません。浅いなら git fetch --unshallow で履歴をすべて取ってから、もう一度実行してください。" >&2
    exit 1
fi
for commit in $commits; do
    count=$((count + 1))
    secbackup_check_commit "$commit" "$commit" || status=1
done
echo "checked ${count} commits"
exit $status
