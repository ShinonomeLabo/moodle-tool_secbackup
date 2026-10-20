#!/usr/bin/env bash
# Writes the development settings that connect the sites of one Moodle version to a backup service other than the fake one
# (for example a copy of the service that runs on this machine), into Moodle's config.php of that version.
# Usage:
#   SECBK_SERVICE_URL=<connection URL> SECBK_SERVICE_KEY=<public key> .dev/env/remote-service.sh <405|500|501|502|503>
#   .dev/env/remote-service.sh <405|500|501|502|503> --off
#  - SECBK_SERVICE_URL: the connection URL that the registration is made with, http(s)://host[:port]/path.
#  - SECBK_SERVICE_KEY: the standard base64 of the Ed25519 public key (32 bytes) that the service signs its answers with.
# The block defines TOOL_SECBACKUP_DEVELOPER with that key as the one trusted key. For an http:// URL it also accepts http,
# private hosts and other ports, and skips the network checks, because a service reached over http runs on this machine or
# in a private network; for an https:// URL nothing else is changed. Only one block may define the constant, so the block
# takes the place of the development settings of devconfig.sh (the fake service). A second run replaces the block.
# --off removes the block; .dev/env/devconfig.sh <ver> then adds the settings of the fake service again.
# The config.php of a version is shared by the sites of all its databases.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?usage: remote-service.sh <405|500|501|502|503> [--off]}" pgsql
config="$SECBK_MOODLE/config.php"
mode="${2:-on}"
case "$mode" in
    on | --off) ;;
    *) echo "usage: remote-service.sh <405|500|501|502|503> [--off]" >&2; exit 2 ;;
esac
if [ ! -f "$config" ]; then
    echo "no config.php: $config (install the site first)" >&2
    exit 1
fi

block=''
if [ "$mode" = on ]; then
    url="${SECBK_SERVICE_URL:?set SECBK_SERVICE_URL to the connection URL}"
    key="${SECBK_SERVICE_KEY:?set SECBK_SERVICE_KEY to the base64 public key of the service}"
    if [[ ! "$url" =~ ^(https?)://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~-]+)*/?$ ]]; then
        echo "SECBK_SERVICE_URL is not a connection URL: http(s)://host[:port]/path" >&2
        exit 1
    fi
    scheme="${BASH_REMATCH[1]}"
    # Only the canonical text of 32 bytes: the plugin refuses anything else when it reads the constant.
    if ! php -r '
        $raw = base64_decode($argv[1], true);
        exit($raw !== false && strlen($raw) === 32 && base64_encode($raw) === $argv[1] ? 0 : 1);
    ' "$key"; then
        echo "SECBK_SERVICE_KEY is not the standard base64 of a 32-byte public key" >&2
        exit 1
    fi
    switches=''
    if [ "$scheme" = http ]; then
        switches=$'\n    \x27allowhttp\x27 => true,\n    \x27allowprivatehosts\x27 => true,\n    \x27skipnetworkchecks\x27 => true,'
    fi
    block="// tool_secbackup remote service settings (added by .dev/env/remote-service.sh).
// Connection URL: $url
define('TOOL_SECBACKUP_DEVELOPER', [
    'trustedkeys' => ['$key'],$switches
]);
// End of tool_secbackup remote service settings."
fi

# Removes the block of the remote service (and, when a new block is written, the development settings of devconfig.sh), then
# puts the new block (if any) before the line that loads Moodle.
php -r '
    [, $file, $block] = $argv;
    $text = file_get_contents($file);
    if ($text === false) {
        fwrite(STDERR, "config.php cannot be read\n");
        exit(1);
    }
    $blocks = [
        "remote" => ["tool_secbackup remote service settings", "End of tool_secbackup remote service settings."],
    ];
    if ($block !== "") {
        $blocks["fake"] = ["tool_secbackup development settings", "End of tool_secbackup development settings."];
    }
    foreach ($blocks as $name => [$start, $end]) {
        $pattern = "~^// " . preg_quote($start, "~") . "[^\n]*\n.*?^// " . preg_quote($end, "~") . "\n(\n)?~ms";
        $text = preg_replace($pattern, "", $text, -1, $count);
        if ($count > 0) {
            echo "removed the $name settings\n";
        }
    }
    if ($block !== "") {
        $at = strrpos($text, "require_once");
        if ($at === false) {
            fwrite(STDERR, "config.php has no require_once line\n");
            exit(1);
        }
        $text = substr($text, 0, $at) . $block . "\n\n" . substr($text, $at);
        echo "added the remote service settings\n";
    }
    if (file_put_contents($file, $text) === false) {
        fwrite(STDERR, "config.php cannot be written\n");
        exit(1);
    }
' "$config" "$block"
echo "config: $config"
if [ "$mode" = --off ]; then
    echo "to use the fake service again: .dev/env/devconfig.sh $1"
fi
