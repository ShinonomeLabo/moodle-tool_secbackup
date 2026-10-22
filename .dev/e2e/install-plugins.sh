#!/usr/bin/env bash
# Puts the extra plugins of the staging end-to-end test into a Moodle checkout, each at the commit that plugins.json names: a
# commit and not a tag or a branch, so that a tag or a branch that moves cannot change what is tested. Development only.
# Usage: .dev/e2e/install-plugins.sh <Moodle folder> [--skip <component>]...
#
# Each row of plugins.json is {"component", "dir", "repo", "commit"}. dir is relative to the folder that Moodle serves
# (public/ from Moodle 5.1 on, the checkout itself before). The folder gets a git repository of its own, which fetches only
# that commit from repo (depth 1) and checks it out. A commit that cannot be fetched, a checkout that is not that commit, and
# a folder that is there already (unless it holds the same commit: then the row is left as it is, so a second run does
# nothing) stop the script with an error. --skip <component> leaves out every row of that component, for a plugin that does
# not install on the Moodle version; check-data.php takes the same plugins with --skip.
# The rows are put in their order, so a row may name a folder inside the folder of an earlier row: block_autoattend needs its
# library jbxl in blocks/autoattend/jbxl (the plugin's script setup_jbxl clones it there from git, and the package of the
# plugin on moodle.org contains it), and the .gitignore of the plugin leaves that folder out.
# After this script, admin/cli/upgrade.php installs the plugins.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
usage="usage: install-plugins.sh <Moodle folder> [--skip <component>]..."
moodle="${1:?$usage}"
shift
skips=()
while [ $# -gt 0 ]; do
    case "$1" in
        --skip) skips+=("${2:?$usage}"); shift 2 ;;
        --skip=*) skips+=("${1#--skip=}"); shift ;;
        *) echo "$usage" >&2; exit 2 ;;
    esac
done
if [ ! -f "$moodle/version.php" ]; then
    echo "not a Moodle checkout (no version.php): $moodle" >&2
    exit 1
fi
root="$moodle"
if [ -d "$moodle/public" ]; then root="$moodle/public"; fi

# The rows as lines of tab-separated fields. A row with a missing or odd field stops the script here, before anything is
# fetched: dir may not leave the folder of Moodle, repo is an https URL and commit is a full SHA-1.
# shellcheck disable=SC2016
rows="$(php -r '
    $rows = json_decode((string) file_get_contents($argv[1]), true);
    if (!is_array($rows) || !$rows || !array_is_list($rows)) {
        fwrite(STDERR, "plugins.json is not a list of rows\n");
        exit(1);
    }
    foreach ($rows as $i => $row) {
        $ok = is_array($row) && array_keys($row) === ["component", "dir", "repo", "commit"]
            && preg_match("/^[a-z]+_[a-z0-9_]+\$/", (string) $row["component"])
            && preg_match("~^[a-z0-9_]+(/[a-z0-9_]+)*\$~", (string) $row["dir"])
            && preg_match("~^https://[A-Za-z0-9.-]+/[A-Za-z0-9._/-]+\$~", (string) $row["repo"])
            && preg_match("/^[0-9a-f]{40}\$/", (string) $row["commit"]);
        if (!$ok) {
            $number = $i + 1;
            fwrite(STDERR, "row $number of plugins.json is not {\"component\", \"dir\", \"repo\", \"commit\"} as expected\n");
            exit(1);
        }
        echo implode("\t", $row), "\n";
    }
' "$here/plugins.json")"
for skip in ${skips[@]+"${skips[@]}"}; do
    if ! awk -F'\t' -v c="$skip" '$1 == c { found = 1 } END { exit !found }' <<< "$rows"; then
        echo "--skip $skip: plugins.json has no row of that component" >&2
        exit 1
    fi
done

while IFS=$'\t' read -r component dir repo commit; do
    skipped=no
    for skip in ${skips[@]+"${skips[@]}"}; do
        if [ "$skip" = "$component" ]; then skipped=yes; fi
    done
    if [ "$skipped" = yes ]; then
        echo "skipped $component $dir"
        continue
    fi
    target="$root/$dir"
    if [ -e "$target" ]; then
        if [ -d "$target/.git" ] && [ "$(git -C "$target" rev-parse HEAD 2> /dev/null)" = "$commit" ]; then
            echo "there   $component $dir $commit"
            continue
        fi
        echo "$target is there already and does not hold $component at $commit" >&2
        exit 1
    fi
    mkdir -p "$target"
    git -C "$target" -c init.defaultBranch=main init --quiet
    git -C "$target" remote add origin "$repo"
    if ! git -C "$target" fetch --quiet --depth 1 origin "$commit"; then
        echo "could not fetch $component at $commit from $repo" >&2
        exit 1
    fi
    git -C "$target" checkout --quiet "$commit"
    actual="$(git -C "$target" rev-parse HEAD)"
    if [ "$actual" != "$commit" ]; then
        echo "$component: the checkout is $actual, not $commit" >&2
        exit 1
    fi
    echo "put     $component $dir $commit"
done <<< "$rows"
