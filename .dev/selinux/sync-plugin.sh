#!/usr/bin/env bash
# Copies the plugin repository into the Moodle of the SELinux VM, upgrades Moodle and restores the SELinux labels.
# Development only. Usage (on the Mac): .dev/selinux/sync-plugin.sh
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
limactl shell secbk-selinux sudo rsync -a --delete --exclude .git "$repo/" /var/www/html/moodle/admin/tool/secbackup/
limactl shell secbk-selinux sudo chown -R apache:apache /var/www/html/moodle/admin/tool/secbackup
limactl shell secbk-selinux sudo restorecon -R /var/www/html/moodle/admin/tool/secbackup
limactl shell secbk-selinux sudo -u apache php /var/www/html/moodle/admin/cli/upgrade.php --non-interactive
echo "synced"
