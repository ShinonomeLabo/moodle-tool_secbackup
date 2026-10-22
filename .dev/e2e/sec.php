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
 * Development only: the test endpoints of the staging backup service, for the staging end-to-end test.
 *
 * Usage:
 *   php .dev/e2e/sec.php reset
 *   php .dev/e2e/sec.php provision --name=<name> --moodle-url=<URL> --source-ip=<IP> --out=<folder>
 *   php .dev/e2e/sec.php inspect --site=<site ID> --out=<file>
 *
 * The connection comes from the environment: SECBK_E2E_BASE_URL, the URL of the API of the service (https://host/path; http
 * only for the loopback address, which the tests use), and SECBK_E2E_SECRET, the value of the header X-E2E-Secret. Neither is
 * ever printed, and nor is the registration code, a key or anything of an answer but its status and some numbers.
 *
 * reset removes the test sites of the service. provision makes an X25519 key pair that lives only in this process, asks the
 * service for a site (a new one, or a new registration code for the one of that name) and opens the code, which the service
 * sealed with the public key of the pair: the code goes to <folder>/code and the site ID and its two public keys to
 * <folder>/site.json, both of mode 0600 in a folder of mode 0700. inspect writes the answer about one site to <file>: only
 * the fields that this script knows, so that nothing that the service may add later (an address, for example) gets into
 * a file that the workflow keeps; an answer other than 2xx is written as {"error": <code>, "http_status": <status>}.
 *
 * Exit codes: 0 done, 1 the service answered with an error or not as expected (or not at all), 2 wrong usage or settings.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Only the command line runs it: a site installed with git clone has this directory under its web root.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

umask(0077);

/** @var string The pattern of a site ID. It ends with \z: $ also matches before a final line feed. */
const SECBK_E2E_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

/**
 * Prints one line and ends the script.
 *
 * @param string $line The line.
 * @param int $code Exit code.
 */
function secbk_e2e_stop(string $line, int $code): void {
    fwrite($code === 0 ? STDOUT : STDERR, $line . "\n");
    exit($code);
}

/**
 * The URL of the API of the service, without a final slash.
 *
 * @return string
 */
function secbk_e2e_base(): string {
    $url = (string) getenv('SECBK_E2E_BASE_URL');
    $pattern = '~^(https?)://([A-Za-z0-9.-]+)(:[0-9]{1,5})?((/[A-Za-z0-9._\~-]+)*)/?\z~';
    if (
        preg_match($pattern, $url, $m) !== 1
        || ($m[1] === 'http' && !in_array(strtolower($m[2]), ['127.0.0.1', 'localhost'], true))
    ) {
        // The value is not printed: it may be the secret URL with a mistake in it.
        secbk_e2e_stop('SECBK_E2E_BASE_URL is not set to a URL of the form https://host[:port]/path', 2);
    }
    return rtrim($url, '/');
}

/**
 * The secret of the test endpoints.
 *
 * @return string
 */
function secbk_e2e_secret(): string {
    $secret = (string) getenv('SECBK_E2E_SECRET');
    // Printable characters only, so that the value cannot end the header and start another.
    if (preg_match('/^[\x21-\x7e]{1,512}\z/', $secret) !== 1) {
        secbk_e2e_stop('SECBK_E2E_SECRET is not set to a value that a header can carry', 2);
    }
    return $secret;
}

/**
 * Sends one request to the test endpoints. Redirects are not followed.
 *
 * @param string $method GET or POST.
 * @param string $path The path below the URL of the API, such as /e2e/reset.
 * @param array|null $json The body, sent as JSON; null for none.
 * @param int $timeout Seconds that the whole request may take.
 * @return array [HTTP status, body, curl error number]; the status is 0 when nothing was answered.
 */
function secbk_e2e_request(string $method, string $path, ?array $json, int $timeout): array {
    $headers = ['Accept: application/json', 'X-E2E-Secret: ' . secbk_e2e_secret()];
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
    ];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    $curl = curl_init(secbk_e2e_base() . $path);
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $errno = curl_errno($curl);
    $status = $errno === 0 ? (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) : 0;
    curl_close($curl);
    return [$status, is_string($body) ? $body : '', $errno];
}

/**
 * Ends the script when the request was not answered with 2xx: prints the status (and, for some answers, the names that
 * they give) and never the body, which may hold an address.
 *
 * @param string $command The command, for the line.
 * @param array $answer What secbk_e2e_request() returned.
 */
function secbk_e2e_require_success(string $command, array $answer): void {
    [$status, $body, $errno] = $answer;
    if ($status === 0) {
        // Only the number: the message of curl names the host.
        secbk_e2e_stop("$command: no answer (curl error $errno)", 1);
    }
    if ($status >= 200 && $status < 300) {
        return;
    }
    $detail = '';
    $data = json_decode($body, true);
    if ($status === 422 && is_array($data) && is_array($data['errors'] ?? null)) {
        $fields = array_filter(array_keys($data['errors']), static fn($key): bool => is_string($key)
            && preg_match('/^[a-z_]{1,40}\z/', $key) === 1);
        $detail = $fields ? ' (refused: ' . implode(', ', $fields) . ')' : '';
    }
    secbk_e2e_stop("$command: HTTP $status$detail", 1);
}

/**
 * Writes a file through a file next to it, so that a reader never sees half of it.
 *
 * @param string $file The file.
 * @param string $content The content.
 * @param int $mode The mode of the file.
 * @return bool
 */
function secbk_e2e_write(string $file, string $content, int $mode): bool {
    $part = $file . '.part';
    if (@file_put_contents($part, $content) !== strlen($content) || !chmod($part, $mode) || !rename($part, $file)) {
        @unlink($part);
        return false;
    }
    return true;
}

/**
 * The value of an option of the command line.
 *
 * @param array $options The options that were given.
 * @param string $name The name of the option.
 * @param string $usage The usage of the command.
 * @return string
 */
function secbk_e2e_option(array $options, string $name, string $usage): string {
    if (!isset($options[$name]) || $options[$name] === '') {
        secbk_e2e_stop($usage, 2);
    }
    return $options[$name];
}

/**
 * A whole number of an answer, or null.
 *
 * @param mixed $value The value.
 * @return int|null
 */
function secbk_e2e_int(mixed $value): ?int {
    return is_int($value) && $value >= 0 ? $value : null;
}

/**
 * A short code or word of an answer (a status, a reason, a time), or null.
 *
 * @param mixed $value The value.
 * @param string $pattern The characters it may have.
 * @return string|null
 */
function secbk_e2e_word(mixed $value, string $pattern = '/^[a-z_]{1,40}\z/'): ?string {
    return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
}

/**
 * The fields of an answer of the inspection that this script knows, or null when the answer does not have their form.
 *
 * @param mixed $data The decoded answer.
 * @return array|null
 */
function secbk_e2e_inspection(mixed $data): ?array {
    $objects = is_array($data) ? ($data['objects'] ?? null) : null;
    if (
        !is_array($data) || !is_string($data['site_uuid'] ?? null) || preg_match(SECBK_E2E_UUID, $data['site_uuid']) !== 1
        || !is_array($data['runs'] ?? null) || !array_is_list($data['runs']) || !is_array($objects)
        || !is_array($objects['lock'] ?? null) || secbk_e2e_int($objects['count'] ?? null) === null
        || secbk_e2e_int($objects['bytes'] ?? null) === null || !is_bool($objects['lock']['compliance'] ?? null)
    ) {
        return null;
    }
    $time = '/^[0-9T:.+Z-]{1,40}\z/';
    $runs = [];
    foreach ($data['runs'] as $run) {
        if (!is_array($run)) {
            return null;
        }
        $runs[] = [
            'uuid' => is_string($run['uuid'] ?? null) && preg_match(SECBK_E2E_UUID, $run['uuid']) === 1 ? $run['uuid'] : null,
            'status' => secbk_e2e_word($run['status'] ?? null),
            'failure_code' => secbk_e2e_word($run['failure_code'] ?? null),
            'requested_bytes' => secbk_e2e_int($run['requested_bytes'] ?? null),
            'has_receipt' => ($run['has_receipt'] ?? null) === true,
            // When the run began, completed and failed; updated_at also moves with writes after the end.
            'started_at' => secbk_e2e_word($run['started_at'] ?? null, $time),
            'completed_at' => secbk_e2e_word($run['completed_at'] ?? null, $time),
            'failed_at' => secbk_e2e_word($run['failed_at'] ?? null, $time),
            'created_at' => secbk_e2e_word($run['created_at'] ?? null, $time),
            'updated_at' => secbk_e2e_word($run['updated_at'] ?? null, $time),
        ];
    }
    $kinds = [];
    foreach (['objects', 'manifests', 'indexes', 'receipts', 'other'] as $kind) {
        $kinds[$kind] = secbk_e2e_int($objects['by_kind'][$kind] ?? null) ?? 0;
    }
    $classes = [];
    foreach (is_array($objects['storage_classes'] ?? null) ? $objects['storage_classes'] : [] as $class => $count) {
        if (is_string($class) && preg_match('/^[A-Z_]{1,40}\z/', $class) === 1 && secbk_e2e_int($count) !== null) {
            $classes[$class] = $count;
        } else {
            return null;
        }
    }
    $lock = $objects['lock'];
    return [
        'site_uuid' => $data['site_uuid'],
        'runs' => $runs,
        'objects' => [
            'count' => $objects['count'],
            'bytes' => $objects['bytes'],
            'by_kind' => $kinds,
            'lock' => [
                'compliance' => $lock['compliance'],
                'missing_keys' => secbk_e2e_int($lock['missing_keys'] ?? null) ?? 0,
                'expired_keys' => secbk_e2e_int($lock['expired_keys'] ?? null) ?? 0,
                'gone_keys' => secbk_e2e_int($lock['gone_keys'] ?? null) ?? 0,
                'min_retain_until' => secbk_e2e_word($lock['min_retain_until'] ?? null, $time),
            ],
            'storage_classes' => (object) $classes,
        ],
    ];
}

/**
 * Whether a text is the standard base64 of a value of some bytes.
 *
 * @param mixed $value The text.
 * @param int $bytes The number of bytes.
 * @return bool
 */
function secbk_e2e_base64(mixed $value, int $bytes): bool {
    $raw = is_string($value) ? base64_decode($value, true) : false;
    return $raw !== false && strlen($raw) === $bytes && base64_encode($raw) === $value;
}

$given = array_slice($argv, 1);
$command = (string) array_shift($given);
$options = [];
foreach ($given as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)\z/s', $argument, $m) !== 1 || isset($options[$m[1]])) {
        secbk_e2e_stop("sec.php: the argument $argument is not --name=value, or is given twice", 2);
    }
    $options[$m[1]] = $m[2];
}
$known = ['reset' => [], 'provision' => ['name', 'moodle-url', 'source-ip', 'out'], 'inspect' => ['site', 'out']];
$usage = 'Usage: php sec.php reset | provision --name=<name> --moodle-url=<URL> --source-ip=<IP> --out=<folder>'
    . ' | inspect --site=<site ID> --out=<file>';
if (!isset($known[$command]) || array_diff(array_keys($options), $known[$command])) {
    secbk_e2e_stop($usage, 2);
}
// The settings are checked before anything is sent.
secbk_e2e_base();
secbk_e2e_secret();

if ($command === 'reset') {
    $answer = secbk_e2e_request('POST', '/e2e/reset', [], 120);
    secbk_e2e_require_success('reset', $answer);
    $data = json_decode($answer[1], true);
    $deleted = is_array($data) ? secbk_e2e_int($data['deleted'] ?? null) : null;
    if ($deleted === null) {
        secbk_e2e_stop("reset: HTTP {$answer[0]}, but the answer does not say how many sites were removed", 1);
    }
    secbk_e2e_stop("reset: HTTP {$answer[0]}, $deleted sites removed", 0);
}

if ($command === 'provision') {
    $name = secbk_e2e_option($options, 'name', $usage);
    $moodleurl = secbk_e2e_option($options, 'moodle-url', $usage);
    $sourceip = secbk_e2e_option($options, 'source-ip', $usage);
    $out = secbk_e2e_option($options, 'out', $usage);
    if (preg_match('/^[A-Za-z0-9-]{1,40}\z/', $name) !== 1) {
        secbk_e2e_stop('provision: --name has to be 1 to 40 letters, digits and hyphens', 2);
    }
    if (preg_match('~^https://[A-Za-z0-9.-]+\z~', $moodleurl) !== 1) {
        secbk_e2e_stop('provision: --moodle-url has to be https://<host name>', 2);
    }
    if (filter_var($sourceip, FILTER_VALIDATE_IP) === false) {
        secbk_e2e_stop('provision: --source-ip is not an IP address', 2);
    }
    if ((!is_dir($out) && !@mkdir($out, 0700, true)) || !chmod($out, 0700)) {
        secbk_e2e_stop('provision: the folder of --out cannot be made', 2);
    }
    // The key pair lives only in this process: the code is sealed for it, and nobody else can open the code.
    $pair = sodium_crypto_box_keypair();
    $answer = secbk_e2e_request('POST', '/e2e/sites', [
        'name' => $name,
        'moodle_url' => $moodleurl,
        'source_ip' => $sourceip,
        'seal_public_key' => base64_encode(sodium_crypto_box_publickey($pair)),
    ], 120);
    secbk_e2e_require_success('provision', $answer);
    $data = json_decode($answer[1], true);
    $sealed = is_array($data) && is_string($data['sealed_code'] ?? null) ? base64_decode($data['sealed_code'], true) : false;
    $code = is_string($sealed) ? sodium_crypto_box_seal_open($sealed, $pair) : false;
    sodium_memzero($pair);
    $sitekey = is_array($data) && is_string($data['site_public_key'] ?? null) ? $data['site_public_key'] : '';
    if (
        !is_array($data) || !is_string($data['site_uuid'] ?? null) || preg_match(SECBK_E2E_UUID, $data['site_uuid']) !== 1
        || !str_starts_with($sitekey, 'secbk1:') || !secbk_e2e_base64(substr($sitekey, strlen('secbk1:')), 32)
        || !secbk_e2e_base64($data['signing_public_key'] ?? null, 32)
        || !is_string($code) || preg_match('/^[A-Z0-9-]{8,80}\z/', $code) !== 1
    ) {
        secbk_e2e_stop("provision: HTTP {$answer[0]}, but the answer is not a site with a sealed registration code", 1);
    }
    $site = json_encode([
        'site_uuid' => $data['site_uuid'],
        'site_public_key' => $sitekey,
        'signing_public_key' => $data['signing_public_key'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (!secbk_e2e_write("$out/code", $code, 0600) || !secbk_e2e_write("$out/site.json", $site, 0600)) {
        secbk_e2e_stop('provision: the registration code or the site cannot be written to the folder of --out', 1);
    }
    secbk_e2e_stop("provision: HTTP {$answer[0]}, the registration code was opened and kept with the keys of the site", 0);
}

// The command inspect.
$site = secbk_e2e_option($options, 'site', $usage);
$out = secbk_e2e_option($options, 'out', $usage);
if (preg_match(SECBK_E2E_UUID, $site) !== 1) {
    secbk_e2e_stop('inspect: --site is not a site ID', 2);
}
$answer = secbk_e2e_request('GET', '/e2e/sites/' . $site, null, 300);
[$status, $body] = $answer;
$data = json_decode($body, true);
if ($status < 200 || $status >= 300) {
    $error = is_array($data) ? secbk_e2e_word($data['error'] ?? null) : null;
    if ($status !== 0) {
        // What summary.php reads: the code of the error, never the body of the answer.
        $text = json_encode(['error' => $error ?? "http_$status", 'http_status' => $status]) . "\n";
        secbk_e2e_write($out, $text, 0644);
        secbk_e2e_stop("inspect: HTTP $status" . ($error === null ? '' : " ($error)"), 1);
    }
    secbk_e2e_require_success('inspect', $answer);
}
$inspection = secbk_e2e_inspection($data);
if ($inspection === null) {
    secbk_e2e_write($out, json_encode(['error' => 'unexpected_answer', 'http_status' => $status]) . "\n", 0644);
    secbk_e2e_stop("inspect: HTTP $status, but the answer is not the state of a site", 1);
}
if (!secbk_e2e_write($out, json_encode($inspection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0644)) {
    secbk_e2e_stop('inspect: the file of --out cannot be written', 1);
}
$states = array_count_values(array_map(static fn(array $run): string => (string) $run['status'], $inspection['runs']));
ksort($states);
$text = implode(', ', array_map(static fn(string $state, int $count): string => "$state $count", array_keys($states), $states));
secbk_e2e_stop(sprintf('inspect: HTTP %d, %d runs (%s), %d objects, %d bytes', $status, count($inspection['runs']),
    $text === '' ? 'none' : $text, $inspection['objects']['count'], $inspection['objects']['bytes']), 0);
