<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Development only: makes this site ready for a whole backup run against the fake backup service.
 *
 * In order: the fake service is reset and told which encryption public key it accepts; the site is registered with it (as
 * the Behat step does, without the pages); the fake service sends the settings of the backup, with a window of one hour that
 * starts twelve hours from now, so that only a start by hand (the button, or cli/backup.php --now) begins a run; the regular
 * status request receives them; and the list of the official files of this Moodle build is made from this site and published,
 * so that a run sends the added plugins as code but not the whole Moodle program (the fake service keeps every object in one
 * file of its state, which a whole program would make too large). The developer settings must trust the key of the fake
 * service and allow its address (.dev/env/devconfig.sh writes such settings).
 *
 * Usage: php <plugin>/.dev/fakesec/prepare.php [--publickey=secbk1:...] [--fake=http://localhost:8099]
 *   --publickey  the site's encryption public key; the fixed key of the fake site when it is left out. A key pair of the run's
 *                own lets .dev/fakesec/verify.php open the backup afterwards.
 *   --fake       the address of the fake service.
 * Prints what it did, never a key or the registration code.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../../tests/classes/envelope_builder.php');
require_once(__DIR__ . '/../../tests/classes/fake_service.php');

use tool_secbackup\local\backup\core_diff;
use tool_secbackup\local\backup\settings;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\enroller;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\fake_service;

[$options, $unrecognised] = cli_get_params(
    ['publickey' => fake_service::ENCRYPTION_KEY, 'fake' => 'http://localhost:8099', 'help' => false],
    ['h' => 'help']
);
if ($unrecognised || $options['help']) {
    cli_writeln('Usage: php .dev/fakesec/prepare.php [--publickey=secbk1:...] [--fake=http://localhost:8099]');
    exit($unrecognised ? 1 : 0);
}
$fake = rtrim($options['fake'], '/');

/**
 * Sends a control request to the fake service and stops when it is not accepted.
 *
 * @param string $fake Address of the fake service.
 * @param string $action The action after /_fake/.
 * @param string|null $body The body, or null for none.
 * @param string $type Content type of the body.
 */
function secbk_dev_control(string $fake, string $action, ?string $body = null, string $type = 'application/json'): void {
    $client = new \core\http_client(['ignoresecurity' => true]);
    $options = ['http_errors' => false, 'timeout' => 120];
    if ($body !== null) {
        $options['body'] = $body;
        $options['headers'] = ['Content-Type' => $type];
    }
    $status = $client->post($fake . '/_fake/' . $action, $options)->getStatusCode();
    if ($status !== 204) {
        cli_error("The fake backup service did not accept \"$action\" (HTTP $status).");
    }
}

secbk_dev_control($fake, 'reset');
secbk_dev_control($fake, 'encryptionkey', trim($options['publickey']), 'text/plain');
$endpoint = endpoint::parse($fake . '/api/lms-backup/v1', developer_settings::current());
enroller::create()->enroll($endpoint, public_key_format::parse($options['publickey']), fake_service::CODE);
cli_writeln('registered: site ' . state::load()->site_id() . ', encryption key fingerprint '
    . state::load()->encryption_fingerprint());

// The service sends times on the hour or the half hour.
$from = intdiv(time() + 12 * HOURSECS, 1800) * 1800;
secbk_dev_control($fake, 'instruction/settings', json_encode([
    'seq' => 1,
    'body' => [
        'timezone' => 'UTC',
        'window_start' => gmdate('H:i', $from),
        'window_end' => gmdate('H:i', $from + HOURSECS),
        'runs_per_day' => 1,
        'full_interval' => 'weekly',
        'bandwidth_mbps' => 0,
        'include_logs' => false,
    ],
]));
(new \tool_secbackup\task\poll_status())->execute();
if (!settings::current()->is_received()) {
    cli_error('The settings of the backup were not received.');
}
cli_writeln('settings received: window ' . gmdate('H:i', $from) . '-' . gmdate('H:i', $from + HOURSECS) . ' UTC');

$diff = new core_diff();
$parts = $diff->release_parts();
$lines = '';
$count = 0;
foreach ($diff->local_hashes() as $path => $sha256) {
    $lines .= $path . "\t" . $sha256 . "\n";
    $count++;
}
$header = json_encode(['v' => 1, 'version' => $parts['version'], 'release' => $parts['release'], 'build' => $parts['build'],
    'count' => $count], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
secbk_dev_control($fake, 'core-manifest', json_encode([
    'version' => $parts['version'],
    'build' => $parts['build'],
    'release' => $parts['release'],
    'count' => $count,
    'content' => base64_encode(gzencode($header . "\n" . $lines, 9)),
]));
cli_writeln("official files published: $count files of {$parts['release']}");
