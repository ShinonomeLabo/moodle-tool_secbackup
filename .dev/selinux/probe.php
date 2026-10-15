<?php
// Development only (SELinux VM): runs the connection diagnostics inside the web server, as httpd_t, and prints them.
// Not part of the plugin package. It refuses to run unless /etc/secbk-selinux-probe exists (made by provision.sh).

if (!is_file('/etc/secbk-selinux-probe')) {
    http_response_code(404);
    exit;
}

define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../../../../config.php');

use tool_secbackup\local\diagnostics\runner;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;

$endpoint = endpoint::parse(required_param('endpoint', PARAM_RAW), developer_settings::current());
header('Content-Type: text/plain; charset=utf-8');
foreach (runner::create()->run($endpoint) as $result) {
    echo "[{$result->result}] {$result->check}: {$result->detail}\n";
    if ($result->advice !== '') {
        echo "    {$result->advice}\n";
    }
}
