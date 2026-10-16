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
 * Sends one unsigned GET to the status of a connection URL with the transport of the plugin.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Sends one unsigned GET to <connection URL>/status with the plugin's transport and prints the HTTP status.
// Development only; not part of the plugin package. Run in the web server container, from the Moodle root:
//   php <plugin path>/.dev/tools/probe-transport.php <connection URL>

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../../config.php');

use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\net\transport_exception;
use tool_secbackup\local\trust\developer_settings;

$endpoint = endpoint::parse($argv[1] ?? '', developer_settings::current());
try {
    $response = transport::create()->send('GET', $endpoint, 'status', ['Accept' => 'application/json'], '');
    echo "HTTP {$response->status}\n";
} catch (transport_exception $e) {
    echo "transport_exception {$e->errorcode} ({$e->debuginfo})\n";
    exit(1);
}
