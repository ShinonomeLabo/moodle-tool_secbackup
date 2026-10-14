#!/usr/bin/env bash
# Runs the plugin's Behat features. Extra arguments go to behat.
# It enables the Behat test site first. That rebuilds the list of features and steps, so a feature or a step file added since
# the site was initialised is not skipped without a word. When the site was initialised for other versions of Moodle or of
# its plugins, it must be initialised again: the script stops and shows the command.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
shift 2
admin="${SECBK_PLUGIN_PATH%/tool/secbackup}"
enable_status=0
enable_output="$("$here/run.sh" "$secbk_ver" "$secbk_db" php "$admin/tool/behat/cli/util_single_run.php" --enable 2>&1)" \
    || enable_status=$?
# 253 is BEHAT_EXITCODE_REINSTALL of Moodle.
if [ "$enable_status" -eq 253 ]; then
    echo "The Behat test site was initialised for other versions of Moodle or of its plugins. Initialise it again:" >&2
    echo "  $here/run.sh $secbk_ver $secbk_db php $admin/tool/behat/cli/init.php --disable-composer" >&2
    exit 1
elif [ "$enable_status" -ne 0 ]; then
    echo "$enable_output" >&2
    echo "util_single_run.php --enable failed (exit $enable_status)" >&2
    exit 1
fi
"$SECBK_REPO/.dev/fakesec/start.sh" "$secbk_ver" "$secbk_db"
"$here/run.sh" "$secbk_ver" "$secbk_db" php vendor/bin/behat \
    --config /var/www/behatdata/behatrun/behat/behat.yml --tags=@tool_secbackup "$@"
