<?php
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
