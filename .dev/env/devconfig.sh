#!/usr/bin/env bash
# Adds the development settings of tool_secbackup to Moodle's config.php of one version (once).
# They trust the fake service's key, accept http://localhost:8099 and skip the network checks.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?usage: devconfig.sh <405|501|502|503>}" pgsql
config="$SECBK_MOODLE/config.php"
if grep -q 'tool_secbackup development settings' "$config"; then
    echo "already set: $config"
    exit 0
fi
block="$(cat <<'PHP'
// tool_secbackup development settings (added by .dev/env/devconfig.sh).
define('TOOL_SECBACKUP_DEVELOPER', [
    'trustedkeys' => ['PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw='],
    'allowhttp' => true,
    'allowprivatehosts' => true,
    'skipnetworkchecks' => true,
]);
// End of tool_secbackup development settings.
PHP
)"
php -r '
    $file = $argv[1];
    $text = file_get_contents($file);
    $at = strrpos($text, "require_once");
    if ($at === false) {
        fwrite(STDERR, "config.php has no require_once line\n");
        exit(1);
    }
    file_put_contents($file, substr($text, 0, $at) . $argv[2] . "\n\n" . substr($text, $at));
' "$config" "$block"
echo "added to $config"
