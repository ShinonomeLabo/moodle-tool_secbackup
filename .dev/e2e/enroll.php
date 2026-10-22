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
 * Development only: registers the site with a backup service without the registration page, then asks for its status once.
 *
 * Usage: php <plugin>/.dev/e2e/enroll.php < {"url": <connection URL>, "site": <site ID>, "key": <public key>, "code": <code>}
 *
 * The values come on the standard input as one JSON object, so that the registration code is never an argument of a process.
 * The steps are those of the registration page: the connection URL is read with the developer settings of config.php, a
 * registration with another site or URL is removed first, and the enroller of the plugin registers the site with the code and
 * the public key of the site. A site that is registered with that site and URL already is left as it is. Then the status is
 * asked for once, which brings the settings of the service (the address ranges of its storage, for example); the backup that
 * the schedule may start after it is not started. It prints states only (never the URL, the code or a key), and on an error
 * only the class of the exception and, for an exception of Moodle, the ID of its message. Exit codes: 0 registered and the
 * status answered, 1 otherwise.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// No trace of an exception keeps the arguments of the calls: the registration code is one of them.
ini_set('zend.exception_ignore_args', '1');

// The same relative path reaches config.php on 4.5 (admin/tool/...) and on 5.1+ (public/admin/tool/...).
require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\deregistration;
use tool_secbackup\local\enrollment\enroller;
use tool_secbackup\local\enrollment\enrollment_code;
use tool_secbackup\local\enrollment\poller;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\trust\developer_settings;

try {
    $in = json_decode((string) stream_get_contents(STDIN), true, 4, JSON_THROW_ON_ERROR);
    foreach (['url', 'site', 'key', 'code'] as $field) {
        // Letters, digits and the signs of URLs, IDs, base64 and codes: nothing that a line of the log could be made of.
        if (!is_array($in) || !is_string($in[$field] ?? null) || preg_match('~^[A-Za-z0-9.:/+=_-]*\z~', $in[$field]) !== 1) {
            fwrite(STDERR, "enroll: the input has no valid value for $field\n");
            exit(1);
        }
    }
    $endpoint = endpoint::parse($in['url'], developer_settings::current());
    $state = state::load();
    if ($state->is_enrolled() && $state->site_id() === $in['site'] && $state->endpoint_url() === $endpoint->url()) {
        cli_writeln('enroll: registered already');
    } else {
        if ($in['code'] === '') {
            fwrite(STDERR, "enroll: no registration code\n");
            exit(1);
        }
        if ($state->is_enrolled()) {
            deregistration::run();
            cli_writeln('enroll: the registration with another site or connection URL was removed');
        }
        $code = enrollment_code::normalize($in['code']);
        if ($code === null) {
            fwrite(STDERR, "enroll: the registration code is not valid\n");
            exit(1);
        }
        enroller::create()->enroll($endpoint, public_key_format::parse($in['key']), $code);
        cli_writeln('enroll: registered');
    }
    // The settings of the service come with the answer to the status. The starter is replaced, so that a backup that the
    // schedule would start now is not started: the scenario starts its runs itself.
    $poll = (new poller(null, null, null, static fn(int $now): ?string => null))->run();
    cli_writeln("enroll: status $poll");
    exit($poll === 'ok' ? 0 : 1);
} catch (\Throwable $e) {
    // Neither the message nor the trace: they may hold a value. The class, and the ID of the message of Moodle.
    fwrite(STDERR, 'enroll: failed: ' . get_class($e) . ($e instanceof \moodle_exception ? ' ' . $e->errorcode : '') . "\n");
    exit(1);
}
