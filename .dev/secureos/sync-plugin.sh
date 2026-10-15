#!/usr/bin/env bash
# Copies the plugin repository (tracked and new files, not ignored ones) into the Moodle of the test VM, gives the files
# to the web user, restores the SELinux labels where SELinux exists, and upgrades Moodle.
# Development only. Usage (on the Mac): [SECBK_VM=<machine>] .dev/secureos/sync-plugin.sh
set -euo pipefail
. "$(dirname "$0")/lib.sh"

# The plugin directory differs: Moodle 5.1 and later serve from public/.
git -C "$repo" ls-files -z --cached --others --exclude-standard \
    | tar -C "$repo" --no-xattrs --no-mac-metadata --null -T - -cf - \
    | vm_stdin sudo sh -c '
        . /etc/secbk-secureos-info
        plugin="$root/admin/tool/secbackup"
        [ -d "$root/public" ] && plugin="$root/public/admin/tool/secbackup"
        rm -rf "$plugin"
        mkdir -p "$plugin"
        tar -xf - -C "$plugin"
        chown -R "$web_user:$web_user" "$plugin"
        if command -v restorecon > /dev/null; then restorecon -R "$plugin"; fi
        sudo -u "$web_user" php "$root/admin/cli/upgrade.php" --non-interactive > /dev/null
    '
echo "synced"
