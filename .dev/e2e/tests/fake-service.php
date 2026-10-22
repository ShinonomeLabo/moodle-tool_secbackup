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
 * Development server that plays the test endpoints of the staging backup service, for the tests of .dev/e2e/sec.php.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Development server that plays the test endpoints of the staging backup service, for the tests of .dev/e2e/sec.php.
// Development only; not part of the plugin package. .dev/e2e/tests/run.php starts it with php -S on 127.0.0.1 and gives it:
//  - SECBK_FAKE_SECRET: the value that the header X-E2E-Secret must have; any other value is answered with 404;
//  - SECBK_FAKE_CODE: the registration code that it seals for the key of the request;
//  - SECBK_FAKE_LOG: a file where it adds one JSON line for each request (method, path, the headers that matter, the body).
// The name "missing" is answered with 404, "broken" with 500, "invalid" with 422, and "unsealable" with a code sealed for
// another key; a site whose ID ends with 0404, 0413 or 0503 is answered with 404, 413 or 503, and one that ends with 0200 with
// 200 and no state. Those answers carry the word SECBK-FAKE-MARKER, which sec.php must not print.

// Only PHP's built-in server runs it: a site installed with git clone has this directory under its web root.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    return;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$secret = (string) ($_SERVER['HTTP_X_E2E_SECRET'] ?? '');
file_put_contents((string) getenv('SECBK_FAKE_LOG'), json_encode([
    'method' => $method,
    'path' => $path,
    'accept' => (string) ($_SERVER['HTTP_ACCEPT'] ?? ''),
    'contenttype' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    'secretok' => hash_equals((string) getenv('SECBK_FAKE_SECRET'), $secret),
    'body' => $body,
]) . "\n", FILE_APPEND);

/**
 * Sends an answer.
 *
 * @param int $status HTTP status.
 * @param mixed $data The body: an array is sent as JSON, a string as an HTML page.
 */
function secbk_fake_answer(int $status, mixed $data): void {
    http_response_code($status);
    if (is_array($data)) {
        header('Content-Type: application/json');
        echo json_encode($data);
    } else {
        header('Content-Type: text/html');
        echo $data;
    }
}

$marker = '<html><body>SECBK-FAKE-MARKER at ' . htmlspecialchars($path) . '</body></html>';
if (!hash_equals((string) getenv('SECBK_FAKE_SECRET'), $secret)) {
    secbk_fake_answer(404, $marker);
    return;
}

$prefix = '/api/lms-backup/e2e';
if ($method === 'POST' && $path === "$prefix/reset") {
    secbk_fake_answer(200, ['deleted' => 2]);
} else if ($method === 'POST' && $path === "$prefix/sites") {
    $input = json_decode($body, true);
    $name = is_array($input) ? (string) ($input['name'] ?? '') : '';
    if ($name === 'missing') {
        secbk_fake_answer(404, $marker);
    } else if ($name === 'broken') {
        secbk_fake_answer(500, $marker);
    } else if ($name === 'invalid') {
        secbk_fake_answer(422, ['message' => 'SECBK-FAKE-MARKER', 'errors' => ['moodle_url' => ['SECBK-FAKE-MARKER']]]);
    } else {
        $key = base64_decode((string) ($input['seal_public_key'] ?? ''), true);
        // The name "unsealable" gets a code sealed for another key, which the key of the request cannot open.
        $key = $name === 'unsealable' ? sodium_crypto_box_publickey(sodium_crypto_box_keypair()) : (string) $key;
        secbk_fake_answer(201, [
            'site_uuid' => '11111111-2222-4333-8444-555555555555',
            'site_public_key' => 'secbk1:' . base64_encode(str_repeat("\x11", 32)),
            'signing_public_key' => base64_encode(str_repeat("\x22", 32)),
            'sealed_code' => base64_encode(sodium_crypto_box_seal((string) getenv('SECBK_FAKE_CODE'), $key)),
        ]);
    }
} else if ($method === 'GET' && preg_match('~^' . $prefix . '/sites/([0-9a-f-]{36})\z~', $path, $m) === 1) {
    $errors = ['0404' => [404, $marker], '0413' => [413, ['error' => 'too_many_objects']],
        '0503' => [503, ['error' => 'lock_read_failed']]];
    $error = $errors[substr($m[1], -4)] ?? null;
    if ($error !== null) {
        secbk_fake_answer($error[0], $error[1]);
        return;
    }
    if (str_ends_with($m[1], '0200')) {
        // Answered with 200, but without the state of the site.
        secbk_fake_answer(200, ['site_uuid' => $m[1], 'runs' => 'SECBK-FAKE-MARKER']);
        return;
    }
    // A field that sec.php does not know (the URL) must not reach the file that it writes.
    secbk_fake_answer(200, [
        'site_uuid' => $m[1],
        'url' => 'https://SECBK-FAKE-MARKER.example/objects',
        'runs' => [
            ['uuid' => 'aaaaaaaa-2222-4333-8444-555555555555', 'status' => 'failed', 'failure_code' => 'server_busy',
                'requested_bytes' => 4000, 'has_receipt' => false, 'started_at' => '2026-10-23T01:00:00+00:00',
                'completed_at' => null, 'failed_at' => '2026-10-23T01:09:00+00:00', 'created_at' => '2026-10-23T01:00:00+00:00',
                'updated_at' => '2026-10-23T01:10:00+00:00', 'url' => 'https://SECBK-FAKE-MARKER.example/run'],
            // A time that is not of its form (here a URL) is not kept.
            ['uuid' => 'bbbbbbbb-2222-4333-8444-555555555555', 'status' => 'completed', 'failure_code' => null,
                'requested_bytes' => 3000, 'has_receipt' => true, 'started_at' => '2026-10-23T01:31:00+00:00',
                'completed_at' => '2026-10-23T01:39:00+00:00', 'failed_at' => 'https://SECBK-FAKE-MARKER.example/failed',
                'created_at' => '2026-10-23T01:30:00+00:00', 'updated_at' => '2026-10-23T01:40:00+00:00'],
        ],
        'objects' => [
            'count' => 12,
            'bytes' => 5000,
            'by_kind' => ['objects' => 9, 'manifests' => 1, 'indexes' => 1, 'receipts' => 1, 'other' => 0],
            'lock' => ['compliance' => true, 'missing_keys' => 0, 'expired_keys' => 0, 'gone_keys' => 0,
                'min_retain_until' => '2026-11-07T01:00:00Z'],
            'storage_classes' => ['INTELLIGENT_TIERING' => 12],
        ],
    ]);
} else {
    secbk_fake_answer(404, $marker);
}
