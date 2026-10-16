#!/usr/bin/env bash
# Checks what a publication of the repository would show, besides the messages (check-history.sh): the files of the tree of
# HEAD, the files of every commit that a ref reaches, the names of those files, and the tags (the name, and for an annotated
# tag its message and its tagger). A word of the local pattern file fails the check; the output names the commit, the file and
# the number of lines, never a line or a word. Run it before the repository is published; the rules job of CI runs it too.
# It fails closed: a list of words, a history or a search that cannot be read is an error. git 2.15 or later is needed.
set -u
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/rules.sh
source "$here/lib/rules.sh"
# The temporary file of a tag message must not stay behind when a signal stops this script.
trap secbackup_remove_message_file EXIT

words="$(secbackup_scan_patterns)" || exit 1
if ! commits="$(git rev-list --all)"; then
    echo "履歴の一覧を取れませんでした。リポジトリの中で実行してください。" >&2
    exit 1
fi
# A shallow clone has only part of the history (see check-history.sh).
if [ "$(git rev-parse --is-shallow-repository)" != false ]; then
    echo "履歴が浅い (shallow) か、浅いかどうかを確かめられませんでした。すべてのコミットは確かめられません。浅いなら git fetch --unshallow で履歴をすべて取ってから、もう一度実行してください。" >&2
    exit 1
fi
if ! tags="$(git for-each-ref --format='%(objectname) %(refname)' refs/tags)"; then
    echo "タグの一覧を取れませんでした。" >&2
    exit 1
fi
status=0
# The tree of HEAD first, on lines of its own: it is what a checkout shows now.
secbackup_check_tree HEAD HEAD "$words" || status=1
count=0
for commit in $commits; do
    count=$((count + 1))
    secbackup_check_tree "$commit" "$commit" "$words" || status=1
done
tagcount=0
while read -r object refname; do
    [ -n "$object" ] || continue
    tagcount=$((tagcount + 1))
    secbackup_check_tag "$object" "$refname" "$words" || status=1
done <<< "$tags"
echo "checked the files of ${count} commits and ${tagcount} tags"
exit $status
