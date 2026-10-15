#!/usr/bin/env bash
# Copies the plugin repository (tracked files and new files that git does not ignore) into the Moodle of the test VM, gives the files
# to the web user, restores the SELinux labels where SELinux exists, and upgrades Moodle.
# New files are copied on purpose: a developer can try a file before committing it. A CI checkout has none, so there it
# copies exactly the tracked files. Where the plugin and admin/cli are (they differ with Moodle 5.1) was found once, by
# provision.sh, and is read from /etc/secbk-secureos-info.
# Development only. Usage (on the Mac): [SECBK_VM=<machine>] .dev/secureos/sync-plugin.sh
set -euo pipefail
. "$(dirname "$0")/lib.sh"

git -C "$repo" ls-files -z --cached --others --exclude-standard \
    | tar -C "$repo" --no-xattrs --no-mac-metadata --null -T - -cf - \
    | vm_stdin sudo sh -c '
        . /etc/secbk-secureos-info
        plugin="$plugin_dir"
        rm -rf "$plugin"
        mkdir -p "$plugin"
        tar -xf - -C "$plugin"
        chown -R "$web_user:$web_user" "$plugin"
        if command -v restorecon > /dev/null; then restorecon -R "$plugin"; fi
        sudo -u "$web_user" php "$admin_cli/upgrade.php" --non-interactive > /dev/null
    '
echo "synced"
