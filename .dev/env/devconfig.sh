#!/usr/bin/env bash
# Adds the development settings of tool_secbackup to Moodle's config.php of one version (once each):
#  - the developer settings: they trust the fake service's key, accept http://localhost:8099 and skip the network checks;
#  - the Behat profiles of the other browsers (firefox, edge, safari; chrome is the profile of moodle-docker), which
#    .dev/env/browser.sh and .dev/env/behat.sh use. The address of the Behat site can be replaced by writing it to the file
#    .secbk-behat-wwwroot in the Moodle folder: Safari runs on the Mac, outside Docker, and cannot reach http://webserver.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?usage: devconfig.sh <405|500|501|502|503>}" pgsql
config="$SECBK_MOODLE/config.php"

# add_block <marker> <text>: puts the text before the line that loads Moodle, unless the marker is already in the file.
add_block() {
    if grep -q "$1" "$config"; then
        echo "already set ($1): $config"
        return 0
    fi
    php -r '
        $file = $argv[1];
        $text = file_get_contents($file);
        $at = strrpos($text, "require_once");
        if ($at === false) {
            fwrite(STDERR, "config.php has no require_once line\n");
            exit(1);
        }
        file_put_contents($file, substr($text, 0, $at) . $argv[2] . "\n\n" . substr($text, $at));
    ' "$config" "$2"
    echo "added ($1) to $config"
}

add_block 'tool_secbackup development settings' "$(cat <<'PHP'
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
add_block 'tool_secbackup Behat browsers' "$(cat <<'PHP'
// tool_secbackup Behat browsers (added by .dev/env/devconfig.sh).
$CFG->behat_profiles['firefox'] = [
    'browser' => 'firefox',
    'wd_host' => 'http://selenium-firefox:4444/wd/hub',
];
$CFG->behat_profiles['edge'] = [
    'browser' => 'MicrosoftEdge',
    'wd_host' => 'http://selenium-edge:4444/wd/hub',
    'capabilities' => ['browserName' => 'MicrosoftEdge'],
];
// safaridriver runs on the host; the container reaches it through host.docker.internal and .dev/env/safari-relay.php.
$CFG->behat_profiles['safari'] = [
    'browser' => 'safari',
    'wd_host' => 'http://host.docker.internal:4446',
    'capabilities' => ['browserName' => 'safari'],
];
if (is_readable(__DIR__ . '/.secbk-behat-wwwroot')) {
    $CFG->behat_wwwroot = trim(file_get_contents(__DIR__ . '/.secbk-behat-wwwroot'));
}
// End of tool_secbackup Behat browsers.
PHP
)"
