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
 * Runs the connection diagnostics inside the web server of a test machine and prints them.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Development only (test VMs): runs the connection diagnostics inside the web server and prints them, then prints what
// the web server's process can read of the access control of the OS. Not part of the plugin package.
// It refuses to run unless /etc/secbk-secureos-probe exists (made by provision.sh).

if (!is_file('/etc/secbk-secureos-probe')) {
    http_response_code(404);
    exit;
}

define('NO_MOODLE_COOKIES', true);
// Moodle 5.1 and later serve from public/, so config.php is at another depth: walk up until it is found.
$dir = __DIR__;
while (!is_file($dir . '/config.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) {
        http_response_code(500);
        exit;
    }
    $dir = $parent;
}
require($dir . '/config.php');

use tool_secbackup\local\diagnostics\runner;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;

header('Content-Type: text/plain; charset=utf-8');
try {
    $endpoint = endpoint::parse(required_param('endpoint', PARAM_RAW), developer_settings::current());
} catch (plugin_exception $e) {
    echo "[dev] endpoint: {$e->errorcode}\n";
    exit;
}

// A positive control for the count of PHP warnings: one warning from a file in this directory.
if (optional_param('control', 0, PARAM_INT) === 1) {
    require(__DIR__ . '/control-warning.php');
}

foreach (runner::create()->run($endpoint) as $result) {
    echo "[{$result->result}] {$result->check}: {$result->detail}\n";
    if ($result->advice !== '') {
        echo "    {$result->advice}\n";
    }
    // The codes behind the two network checks, which the texts of the results do not show.
    if ($result->check === 'dns') {
        echo '[dev] dnsproblem: ' . ($result->code === '' ? 'none' : $result->code) . "\n";
    }
    if ($result->check === 'connection') {
        echo '[dev] connectionerror: ' . ($result->code === '' ? 'none' : $result->code) . "\n";
    }
}

// What this process can read of the access control of the OS.
foreach (
    [
        'attr-current' => '/proc/self/attr/current',
        'attr-apparmor-current' => '/proc/self/attr/apparmor/current',
        'apparmor-enabled' => '/sys/module/apparmor/parameters/enabled',
        'selinux-enforce' => '/sys/fs/selinux/enforce',
    ] as $name => $file
) {
    $content = is_file($file) ? @file_get_contents($file) : false;
    if ($content === false) {
        $content = is_file($file) ? 'unreadable' : 'absent';
    }
    echo "[dev] $name: " . trim(str_replace(["\0", "\n", ' '], ['', '', '_'], $content)) . "\n";
}
