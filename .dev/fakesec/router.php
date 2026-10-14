<?php
// Development server that plays the backup service, for Behat and for trying the pages by hand.
// Development only; not part of the plugin package. Start it with .dev/fakesec/start.sh.
// It loads the same fake service as the PHPUnit tests, which does not use the plugin's classes.

// Only PHP's built-in server runs it: a site installed with git clone has this directory under its web root.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    return;
}

require __DIR__ . '/../../tests/classes/envelope_builder.php';
require __DIR__ . '/../../tests/classes/fake_service.php';

use tool_secbackup\tests\fake_service;

$statefile = sys_get_temp_dir() . '/tool_secbackup_fakesec.json';
$apibase = getenv('SECBK_FAKESEC_URL') ?: 'http://localhost:8099/api/lms-backup/v1';
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$saved = is_file($statefile) ? json_decode((string) file_get_contents($statefile), true) : null;
$state = is_array($saved) ? $saved : fake_service::initial_state();

if ($method === 'GET' && $path === '/_fake/state') {
    header('Content-Type: application/json');
    echo json_encode($state);
    return;
}
if ($method === 'POST' && str_starts_with($path, '/_fake/')) {
    $action = substr($path, strlen('/_fake/'));
    if ($action === 'reset') {
        $state = fake_service::initial_state();
    } else if ($action === 'newcode') {
        $state['codeused'] = false;
    } else if (preg_match('~^mode/([a-z_]*)\z~', $action, $m) === 1) {
        $state['mode'] = $m[1];
    } else if (preg_match('~^status/([a-z_]+)\z~', $action, $m) === 1) {
        $state['status'] = $m[1];
    } else if (preg_match('~^clock/(-?\d+)\z~', $action, $m) === 1) {
        $state['now'] = time() + (int) $m[1];
    } else {
        http_response_code(404);
        return;
    }
    file_put_contents($statefile, json_encode($state), LOCK_EX);
    http_response_code(204);
    return;
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}
$host = (string) preg_replace('/:\d+\z/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$service = new fake_service($apibase, $state);
[$status, $responseheaders, $body] = $service->handle($method, $host, $path, $headers, (string) file_get_contents('php://input'));
file_put_contents($statefile, json_encode($service->state), LOCK_EX);
http_response_code($status);
foreach ($responseheaders as $name => $value) {
    header($name . ': ' . $value);
}
echo $body;
