#!/usr/bin/env bash
# Merges the coverage of every combination of the `test` job of ci.yml (the ones .dev/coverage/run.sh left in
# .dev/coverage/out/<ver>-<db>[-php<version>]/) and requires every statement of every shipped file to have run.
# Fails when a combination is missing, or when a run was made on a commit whose release files differ from HEAD's.
# Usage: .dev/coverage/merge_all.sh        Report: .dev/coverage/out/merge-all.txt, summary: .dev/coverage/out/summary-all.json
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
out="$repo/.dev/coverage/out"
combos="$("$repo/.dev/ci/matrix.sh" --list)"
reports=()
count=0
problems=0
while read -r ver db php; do
    default_php="$(SECBK_PHP='' bash -c 'source "$1" "$2" "$3" && echo "$secbk_php"' _ "$repo/.dev/env/env.sh" "$ver" "$db")"
    name="$ver-$db"
    [ "$php" != "$default_php" ] && name="$name-php$php"
    dir="$out/$name"
    if [ ! -f "$dir/phpunit.xml" ] || [ ! -f "$dir/commit" ]; then
        echo "missing: $name (no $dir/phpunit.xml or commit)" >&2
        problems=1
        continue
    fi
    commit="$(cat "$dir/commit")"
    if ! git -C "$repo" diff --quiet "$commit" HEAD -- . ':!.dev' ':!tests'; then
        echo "stale: $name ran on $commit, and the release files of HEAD differ from that commit" >&2
        problems=1
        continue
    fi
    reports+=("$dir/phpunit.xml")
    for json in "$dir"/*.json; do
        [ -e "$json" ] && reports+=("$json")
    done
    count=$((count + 1))
done <<< "$combos"
echo "combinations: $count of $(printf '%s\n' "$combos" | wc -l | tr -d ' ')"
[ "$problems" -eq 0 ] || exit 1
"$repo/.dev/coverage/shipped_files.sh" > "$out/shipped-all.txt"
php "$repo/.dev/coverage/merge.php" --shipped="$out/shipped-all.txt" --report="$out/merge-all.txt" \
    --summary="$out/summary-all.json" "${reports[@]}"
