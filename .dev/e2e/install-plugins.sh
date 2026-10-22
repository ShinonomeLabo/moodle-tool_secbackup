#!/usr/bin/env bash
# Puts the extra plugins of the staging end-to-end test into a Moodle checkout, each at the commit that plugins.json names: a
# commit and not a tag or a branch, so that a tag or a branch that moves cannot change what is tested. Development only.
# Usage: .dev/e2e/install-plugins.sh <Moodle folder> [--skip <component>[,<component>...]]...
#
# Each row of plugins.json is {"component", "dir", "repo", "commit"}. dir is relative to the folder that Moodle serves
# (public/ from Moodle 5.1 on, the checkout itself before). The first row of a component is the plugin: its dir is the folder
# of that component (mod_x in mod/x, block_x in blocks/x, ...). A later row of the same component is a library inside it:
# block_autoattend needs its library jbxl in blocks/autoattend/jbxl (the plugin's script setup_jbxl clones it there from git,
# and the package of the plugin on moodle.org contains it), and the .gitignore of the plugin leaves that folder out.
# Each row is fetched into a new git repository in a temporary folder next to its place (only that commit, depth 1), checked
# out, and moved to its place when the checkout is that commit. A commit that cannot be fetched or a checkout that is not that
# commit stops the script with an error and leaves nothing behind. A folder that is there already stops it too, unless it
# holds the same commit: then the row is left as it is, so a second run does nothing.
# --skip leaves out every row of the components it names; it may be given more than once (--skip a --skip=b) and may name a
# list (--skip=a,b). check-data.php takes the same. block_autoattend and mod_autoattendmod are left out together: the activity
# needs the block in its course and the library of the block.
# After this script, admin/cli/upgrade.php installs the plugins.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
usage="usage: install-plugins.sh <Moodle folder> [--skip <component>[,<component>...]]..."
# git must fail, not wait for a password, when a repository cannot be read.
export GIT_TERMINAL_PROMPT=0
moodle="${1:?$usage}"
shift
skips=()

# Adds the components of one value of --skip, which may be a list separated by commas.
add_skips() {
    local part parts
    IFS=',' read -r -a parts <<< "$1"
    for part in ${parts[@]+"${parts[@]}"}; do
        part="${part// /}"
        if [ -n "$part" ]; then skips+=("$part"); fi
    done
}

while [ $# -gt 0 ]; do
    case "$1" in
        --skip) add_skips "${2:?$usage}"; shift 2 ;;
        --skip=*) add_skips "${1#--skip=}"; shift ;;
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
# fetched: dir may not leave the folder of Moodle and has to fit the component, repo is an https URL and commit is a full
# SHA-1. The patterns end with \z, so that a value cannot end with a line break.
# shellcheck disable=SC2016
rows="$(php -r '
    $rows = json_decode((string) file_get_contents($argv[1]), true);
    if (!is_array($rows) || !$rows || !array_is_list($rows)) {
        fwrite(STDERR, "plugins.json is not a list of rows\n");
        exit(1);
    }
    // The folder of each plugin type that a row may name (dirroot-relative, as core_component has them).
    $types = ["mod" => "mod", "block" => "blocks", "local" => "local", "tool" => "admin/tool", "report" => "report",
        "theme" => "theme", "format" => "course/format", "qtype" => "question/type", "filter" => "filter", "auth" => "auth",
        "enrol" => "enrol", "repository" => "repository"];
    $plugindirs = [];
    foreach ($rows as $i => $row) {
        $number = $i + 1;
        $ok = is_array($row) && array_keys($row) === ["component", "dir", "repo", "commit"]
            && preg_match("/^[a-z]+_[a-z0-9_]+\z/", (string) $row["component"])
            && preg_match("~^[a-z0-9_]+(/[a-z0-9_]+)*\z~", (string) $row["dir"])
            && preg_match("~^https://[A-Za-z0-9.-]+/[A-Za-z0-9._/-]+\z~", (string) $row["repo"])
            && preg_match("/^[0-9a-f]{40}\z/", (string) $row["commit"]);
        if (!$ok) {
            fwrite(STDERR, "row $number of plugins.json is not {\"component\", \"dir\", \"repo\", \"commit\"} as expected\n");
            exit(1);
        }
        [$type, $name] = explode("_", $row["component"], 2);
        if (!isset($plugindirs[$row["component"]])) {
            $wanted = isset($types[$type]) ? $types[$type] . "/" . $name : null;
            if ($row["dir"] !== $wanted) {
                $place = $wanted ?? "a folder that this script does not know";
                fwrite(STDERR, "row $number of plugins.json: {$row["component"]} belongs in $place, not {$row["dir"]}\n");
                exit(1);
            }
            $plugindirs[$row["component"]] = $row["dir"];
        } else if (strpos($row["dir"], $plugindirs[$row["component"]] . "/") !== 0) {
            fwrite(STDERR, "row $number of plugins.json: a library of {$row["component"]} belongs inside " .
                $plugindirs[$row["component"]] . ", not in {$row["dir"]}\n");
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

# The temporary folder of the row that is being fetched; it is removed when the script stops before the folder is moved.
work=''
trap 'if [ -n "$work" ]; then rm -rf "$work"; fi' EXIT

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
    mkdir -p "$(dirname "$target")"
    work="$(mktemp -d "$(dirname "$target")/.install-plugins.XXXXXX")"
    if ! { git -C "$work" -c init.defaultBranch=main init --quiet &&
        git -C "$work" remote add origin "$repo" &&
        git -C "$work" fetch --quiet --depth 1 origin "$commit" &&
        git -C "$work" checkout --quiet "$commit"; }; then
        echo "could not fetch $component at $commit from $repo" >&2
        exit 1
    fi
    actual="$(git -C "$work" rev-parse HEAD)"
    if [ "$actual" != "$commit" ]; then
        echo "$component: the checkout is $actual, not $commit" >&2
        exit 1
    fi
    mv "$work" "$target"
    work=''
    echo "put     $component $dir $commit"
done <<< "$rows"
