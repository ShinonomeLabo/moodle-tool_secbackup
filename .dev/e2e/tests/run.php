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
 * Tests for .dev/e2e/sec.php, .dev/e2e/verdict.php and .dev/e2e/summary.php.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Tests for the scripts of the staging end-to-end test that need no Moodle. Run: php .dev/e2e/tests/run.php
// Development only; not part of the plugin package. sec.php talks to fake-service.php, which runs under php -S on 127.0.0.1;
// verdict.php and summary.php read files that the tests write in a folder of their own in the temporary folder. The functions
// of lib.sh run in bash, with the ones that reach Moodle, the service, the firewall or other processes replaced by stubs
// that write down the calls; run.sh is run only with arguments that it refuses, or without the variable that lets it run.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$e2e = dirname(__DIR__);
$pass = 0;
$fail = 0;
$work = sys_get_temp_dir() . '/secbk-e2e-test-' . getmypid();
mkdir($work, 0700, true);

/**
 * Runs a command and returns [exit code, output and errors together].
 *
 * The variables of the staging test, the folders of the site and GITHUB_ACTIONS (which lets run.sh build a site) are taken
 * out of the environment of this process, so that neither a shell nor the runner of CI reaches a test; $env sets the ones
 * that the test needs.
 *
 * @param string[] $command The command and its arguments.
 * @param array $env Variables of the environment to set.
 * @return array
 */
function secbk_e2e_command(array $command, array $env = []): array {
    $environment = array_filter(getenv(), static fn(string $name): bool => !str_starts_with($name, 'SECBK_')
        && $name !== 'GITHUB_ACTIONS' && $name !== 'RUNNER_TEMP', ARRAY_FILTER_USE_KEY);
    // Development tooling may run commands; the plugin itself never does.
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null,
        array_merge($environment, $env));
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
}

/**
 * Runs a PHP script of .dev/e2e and returns [exit code, output and errors together].
 *
 * @param string $script sec.php, verdict.php or summary.php.
 * @param string[] $args The arguments.
 * @param array $env Variables of the environment to set.
 * @return array
 */
function secbk_e2e_run(string $script, array $args, array $env = []): array {
    global $e2e;
    return secbk_e2e_command(array_merge([PHP_BINARY, "$e2e/$script"], $args), $env);
}

/**
 * Runs bash code after lib.sh is sourced (with set -euo pipefail, as run.sh has it), with the folder of the site and the
 * folder of the results in the work folder, and returns [exit code, output].
 *
 * @param string $code The code.
 * @param array $env Variables of the environment to set.
 * @return array
 */
function secbk_e2e_lib(string $code, array $env = []): array {
    global $e2e, $work;
    return secbk_e2e_command(['bash', '-c', 'set -euo pipefail; . "$0/lib.sh"; ' . $code, $e2e],
        array_merge(['SECBK_STORAGE_DIR' => "$work/storage", 'SECBK_E2E_OUT' => "$work/out"], $env));
}

/**
 * The result.json of a scenario that a test of lib.sh ran, or null.
 *
 * @param string $name The name of the site.
 * @return array|null
 */
function secbk_e2e_result(string $name): ?array {
    global $work;
    $data = json_decode((string) @file_get_contents("$work/out/$name/result.json"), true);
    return is_array($data) ? $data : null;
}

/**
 * Records one expectation.
 *
 * @param string $label Test label.
 * @param bool $ok Whether it held.
 * @param string $detail Output to show on failure.
 */
function secbk_e2e_expect(string $label, bool $ok, string $detail): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $label\n$detail\n";
    }
}

/**
 * A TCP port of 127.0.0.1 that nothing listens on now.
 *
 * @return int
 */
function secbk_e2e_free_port(): int {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($server, false);
    fclose($server);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * The answer of the inspection of a site that passed the normal scenario, with the changes given.
 *
 * @param array $changes Values that replace those of the answer, by key; objects and lock are merged one level down.
 * @return array
 */
function secbk_e2e_inspection(array $changes = []): array {
    $inspection = [
        'site_uuid' => '11111111-2222-4333-8444-555555555555',
        'runs' => [
            ['uuid' => 'bbbbbbbb-2222-4333-8444-555555555555', 'status' => 'completed', 'failure_code' => null,
                'requested_bytes' => 3000, 'has_receipt' => true, 'started_at' => '2026-10-23T01:30:00+00:00',
                'completed_at' => '2026-10-23T01:40:00+00:00', 'failed_at' => null, 'created_at' => '2026-10-23T01:30:00+00:00',
                'updated_at' => '2026-10-23T01:40:00+00:00'],
        ],
        'objects' => [
            'count' => 12,
            'bytes' => 5000,
            'by_kind' => ['objects' => 9, 'manifests' => 1, 'indexes' => 1, 'receipts' => 1, 'other' => 0],
            'lock' => ['compliance' => true, 'missing_keys' => 0, 'expired_keys' => 0, 'gone_keys' => 0,
                'min_retain_until' => '2026-11-07T01:00:00Z'],
            'storage_classes' => ['INTELLIGENT_TIERING' => 12],
        ],
    ];
    foreach ($changes as $key => $value) {
        if ($key === 'objects') {
            $inspection['objects'] = array_merge($inspection['objects'], $value);
        } else if ($key === 'lock') {
            $inspection['objects']['lock'] = array_merge($inspection['objects']['lock'], $value);
        } else {
            $inspection[$key] = $value;
        }
    }
    return $inspection;
}

/**
 * Writes the files that one job of the workflow leaves: result.json and inspect.json in a folder of its own.
 *
 * @param string $folder The folder of the results.
 * @param string $name The name of the site (also the name of its folder).
 * @param array $result Values that replace those of a result that passed.
 * @param array|string|null $inspection The answer of the inspection; a string is written as it is; null writes no file.
 */
function secbk_e2e_site(string $folder, string $name, array $result = [], array|string|null $inspection = []): void {
    @mkdir("$folder/$name", 0777, true);
    file_put_contents("$folder/$name/result.json", json_encode(array_merge([
        'scenario' => 'normal',
        'name' => $name,
        'passed' => true,
        'reasons' => [],
        'sent_bytes' => 5000,
        'durations' => ['build' => 600, 'backup' => 300, 'total' => 960],
        'retries' => ['waits' => 0, 'failed_steps' => 0],
    ], $result)));
    if ($inspection !== null) {
        file_put_contents("$folder/$name/inspect.json",
            is_string($inspection) ? $inspection : json_encode(secbk_e2e_inspection($inspection)));
    }
}

/**
 * Writes an inspection to a file and returns its path.
 *
 * @param string $name File name in the work folder.
 * @param array|string $inspection The inspection, or the text of the file.
 * @return string
 */
function secbk_e2e_inspect_file(string $name, array|string $inspection): string {
    global $work;
    file_put_contents("$work/$name", is_string($inspection) ? $inspection : json_encode($inspection));
    return "$work/$name";
}

// The fake service.
$port = secbk_e2e_free_port();
$secret = 'secbk-test-secret-' . bin2hex(random_bytes(16));
$code = 'SEC-BK-ABCD-EFGH-JKLM-NPQR';
$log = "$work/fake.log";
touch($log);
$fakeenv = array_merge(getenv(), ['SECBK_FAKE_SECRET' => $secret, 'SECBK_FAKE_CODE' => $code, 'SECBK_FAKE_LOG' => $log]);
// Development tooling may run commands; the plugin itself never does.
$fake = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake-service.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$work/fake.out", 'a'], 2 => ['file', "$work/fake.out", 'a']], $pipes,
    null, $fakeenv);
$ready = false;
for ($i = 0; $i < 100 && !$ready; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($socket) {
        fclose($socket);
        $ready = true;
    } else {
        usleep(100000);
    }
}
secbk_e2e_expect('the fake service starts', $ready, (string) @file_get_contents("$work/fake.out"));
$base = "http://127.0.0.1:$port/api/lms-backup";
$env = ['SECBK_E2E_BASE_URL' => $base, 'SECBK_E2E_SECRET' => $secret];

// 1. provision opens the sealed code with its own key, keeps the code and the keys of the site in files of mode 0600, and
// prints neither the code, the keys, the URL nor the secret.
$out = "$work/r1-normal/private";
[$exit, $output] = secbk_e2e_run('sec.php', ['provision', '--name=r1-normal',
    '--moodle-url=https://r1-normal.moodle.example.invalid', '--source-ip=203.0.113.7', "--out=$out"], $env);
secbk_e2e_expect('provision succeeds', $exit === 0 && str_contains($output, 'HTTP 201'), "exit $exit: $output");
secbk_e2e_expect('the code is kept', (string) @file_get_contents("$out/code") === $code, (string) @file_get_contents("$out/code"));
secbk_e2e_expect('the code file has mode 0600', is_file("$out/code") && (fileperms("$out/code") & 0777) === 0600,
    sprintf('%o', @fileperms("$out/code")));
$site = json_decode((string) @file_get_contents("$out/site.json"), true);
secbk_e2e_expect('site.json holds the site and its keys', $site === [
    'site_uuid' => '11111111-2222-4333-8444-555555555555',
    'site_public_key' => 'secbk1:' . base64_encode(str_repeat("\x11", 32)),
    'signing_public_key' => base64_encode(str_repeat("\x22", 32)),
], (string) @file_get_contents("$out/site.json"));
secbk_e2e_expect('site.json has mode 0600', is_file("$out/site.json") && (fileperms("$out/site.json") & 0777) === 0600,
    sprintf('%o', @fileperms("$out/site.json")));
secbk_e2e_expect('the folder has mode 0700', is_dir($out) && (fileperms($out) & 0777) === 0700, sprintf('%o', @fileperms($out)));
$requests = array_map(static fn(string $line) => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES));
$request = end($requests) ?: [];
$sent = json_decode((string) ($request['body'] ?? ''), true) ?: [];
$sealkey = (string) ($sent['seal_public_key'] ?? '');
secbk_e2e_expect('provision posts the four values as JSON with the secret and Accept: application/json',
    ($request['method'] ?? '') === 'POST' && ($request['path'] ?? '') === '/api/lms-backup/e2e/sites'
    && ($request['secretok'] ?? false) === true && ($request['accept'] ?? '') === 'application/json'
    && str_starts_with((string) ($request['contenttype'] ?? ''), 'application/json')
    && array_keys($sent) === ['name', 'moodle_url', 'source_ip', 'seal_public_key']
    && ($sent['name'] ?? '') === 'r1-normal' && ($sent['moodle_url'] ?? '') === 'https://r1-normal.moodle.example.invalid'
    && ($sent['source_ip'] ?? '') === '203.0.113.7' && strlen((string) base64_decode($sealkey, true)) === 32,
    json_encode($request));
// The service sits behind a firewall that refuses an /api/ request without a User-Agent (the staging run of 2026-10-23
// was refused with 403 at its first request): every request of sec.php names itself.
$agents = array_unique(array_map(static fn(array $r): string => (string) ($r['useragent'] ?? ''), $requests));
secbk_e2e_expect('every request of sec.php carries the User-Agent of the test',
    $requests !== [] && $agents === ['tool_secbackup-e2e/1'], json_encode(array_values($agents)));
foreach (['the code' => $code, 'the code without its dashes' => str_replace('-', '', $code), 'the secret' => $secret,
        'the URL' => $base, 'the address' => "127.0.0.1:$port", 'the path' => '/api/lms-backup', 'the seal key' => $sealkey,
        'the public key of the site' => base64_encode(str_repeat("\x11", 32)),
        'the signing key' => base64_encode(str_repeat("\x22", 32)), 'the source address' => '203.0.113.7'] as $what => $value) {
    secbk_e2e_expect("provision does not print $what", $value !== '' && !str_contains($output, $value), $output);
}

// 2. An answer other than 2xx gives 1 and only the status: never the body, the URL or the secret. Nothing is kept.
foreach (['missing' => 404, 'broken' => 500] as $name => $status) {
    [$exit, $output] = secbk_e2e_run('sec.php', ['provision', "--name=$name", "--moodle-url=https://$name.moodle.example.invalid",
        '--source-ip=203.0.113.7', "--out=$work/$name/private"], $env);
    secbk_e2e_expect("provision answered with $status gives 1 and the status", $exit === 1 && str_contains($output, "HTTP $status"),
        "exit $exit: $output");
    secbk_e2e_expect("provision answered with $status prints nothing of the answer, the URL or the secret",
        !str_contains($output, 'SECBK-FAKE-MARKER') && !str_contains($output, $base) && !str_contains($output, "127.0.0.1:$port")
        && !str_contains($output, $secret), $output);
    secbk_e2e_expect("provision answered with $status keeps no file", !file_exists("$work/$name/private/code")
        && !file_exists("$work/$name/private/site.json"), "$work/$name/private");
}
[$exit, $output] = secbk_e2e_run('sec.php', ['provision', '--name=invalid', '--moodle-url=https://invalid.moodle.example.invalid',
    '--source-ip=203.0.113.7', "--out=$work/invalid/private"], $env);
secbk_e2e_expect('a refused input gives 1 and the names of the fields only', $exit === 1 && str_contains($output, 'HTTP 422')
    && str_contains($output, 'moodle_url') && !str_contains($output, 'SECBK-FAKE-MARKER'), "exit $exit: $output");
[$exit, $output] = secbk_e2e_run('sec.php', ['reset'], ['SECBK_E2E_SECRET' => 'wrong-secret-of-the-same-kind'] + $env);
secbk_e2e_expect('a wrong secret gives 1 and the status 404', $exit === 1 && str_contains($output, 'HTTP 404')
    && !str_contains($output, 'SECBK-FAKE-MARKER') && !str_contains($output, 'wrong-secret'), "exit $exit: $output");
[$exit, $output] = secbk_e2e_run('sec.php', ['reset'], $env);
secbk_e2e_expect('reset prints the number of sites removed', $exit === 0 && str_contains($output, '2 sites'),
    "exit $exit: $output");

// The inspection keeps only the fields that it knows, and an error answer as its code.
$file = "$work/inspect-ok.json";
[$exit, $output] = secbk_e2e_run('sec.php', ['inspect', '--site=11111111-2222-4333-8444-555555555555', "--out=$file"], $env);
$kept = json_decode((string) @file_get_contents($file), true);
secbk_e2e_expect('inspect writes the answer', $exit === 0 && is_array($kept) && count($kept['runs'] ?? []) === 2
    && ($kept['objects']['bytes'] ?? null) === 5000 && ($kept['objects']['lock']['compliance'] ?? null) === true
    && ($kept['objects']['storage_classes'] ?? null) === ['INTELLIGENT_TIERING' => 12], "exit $exit: $output");
secbk_e2e_expect('inspect drops the fields that it does not know', !str_contains((string) @file_get_contents($file), 'MARKER'),
    (string) @file_get_contents($file));
secbk_e2e_expect('inspect keeps the times of the start, the completion and the failure of each run, and drops one that is not'
    . ' a time', ($kept['runs'][0]['started_at'] ?? null) === '2026-10-23T01:00:00+00:00'
    && array_key_exists('completed_at', $kept['runs'][0] ?? []) && $kept['runs'][0]['completed_at'] === null
    && ($kept['runs'][0]['failed_at'] ?? null) === '2026-10-23T01:09:00+00:00'
    && ($kept['runs'][1]['started_at'] ?? null) === '2026-10-23T01:31:00+00:00'
    && ($kept['runs'][1]['completed_at'] ?? null) === '2026-10-23T01:39:00+00:00'
    && array_key_exists('failed_at', $kept['runs'][1] ?? []) && $kept['runs'][1]['failed_at'] === null,
    json_encode($kept['runs'] ?? null));
secbk_e2e_expect('inspect prints numbers, not the site', str_contains($output, '2 runs') && str_contains($output, '5000 bytes')
    && !str_contains($output, '11111111-2222') && !str_contains($output, $base), $output);
$errors = ['0503' => [503, 'lock_read_failed'], '0413' => [413, 'too_many_objects'], '0404' => [404, 'http_404']];
foreach ($errors as $end => $error) {
    $file = "$work/inspect-$end.json";
    [$exit, $output] = secbk_e2e_run('sec.php', ['inspect', "--site=11111111-2222-4333-8444-55555555$end", "--out=$file"], $env);
    $kept = json_decode((string) @file_get_contents($file), true);
    secbk_e2e_expect("inspect answered with {$error[0]} gives 1, the status and the error in the file",
        $exit === 1 && str_contains($output, "HTTP {$error[0]}") && !str_contains($output, 'SECBK-FAKE-MARKER')
        && $kept === ['error' => $error[1], 'http_status' => $error[0]], "exit $exit: $output " . json_encode($kept));
}

// A URL that is not https (other than the loopback address), a missing secret and a service that does not answer.
[$exit, $output] = secbk_e2e_run('sec.php', ['reset'], ['SECBK_E2E_BASE_URL' => 'http://service.example.invalid/api/x'] + $env);
secbk_e2e_expect('an http URL of another host is refused without printing it', $exit === 2
    && !str_contains($output, 'service.example.invalid'), "exit $exit: $output");
[$exit, $output] = secbk_e2e_run('sec.php', ['reset'], ['SECBK_E2E_BASE_URL' => $base]);
secbk_e2e_expect('a missing secret is refused', $exit === 2, "exit $exit: $output");
$closed = secbk_e2e_free_port();
[$exit, $output] = secbk_e2e_run('sec.php', ['reset'], ['SECBK_E2E_BASE_URL' => "http://127.0.0.1:$closed/api/lms-backup"] + $env);
secbk_e2e_expect('a service that does not answer gives 1 without the URL', $exit === 1 && str_contains($output, 'no answer')
    && !str_contains($output, "127.0.0.1:$closed"), "exit $exit: $output");
[$exit, $output] = secbk_e2e_run('sec.php', ['provision', '--name=r1-normal'], $env);
secbk_e2e_expect('provision without its options is refused', $exit === 2, "exit $exit: $output");
// A sealed code that the key of the request cannot open, and an answer of the inspection that is not the state of a site.
[$exit, $output] = secbk_e2e_run('sec.php', ['provision', '--name=unsealable',
    '--moodle-url=https://unsealable.moodle.example.invalid', '--source-ip=203.0.113.7', "--out=$work/unsealable/private"], $env);
secbk_e2e_expect('a code that cannot be opened gives 1 and keeps nothing', $exit === 1
    && str_contains($output, 'HTTP 201, but the answer is not a site with a sealed registration code')
    && !file_exists("$work/unsealable/private/code") && !file_exists("$work/unsealable/private/site.json"), "exit $exit: $output");
$file = "$work/inspect-0200.json";
[$exit, $output] = secbk_e2e_run('sec.php', ['inspect', '--site=11111111-2222-4333-8444-555555550200', "--out=$file"], $env);
secbk_e2e_expect('an answer that is not the state of a site is written as unexpected_answer', $exit === 1
    && str_contains($output, 'HTTP 200, but the answer is not the state of a site')
    && json_decode((string) @file_get_contents($file), true) === ['error' => 'unexpected_answer', 'http_status' => 200]
    && !str_contains($output, 'SECBK-FAKE-MARKER'), "exit $exit: $output " . @file_get_contents($file));

// 3. summary.php adds up objects.bytes of every site: exactly 30 GB (10^9 bytes) passes, one byte more fails.
$folder = "$work/results-limit";
secbk_e2e_site($folder, 'r1-normal', [], ['objects' => ['bytes' => 10000000000]]);
secbk_e2e_site($folder, 'r1-load', ['scenario' => 'load'], ['objects' => ['bytes' => 12000000000]]);
secbk_e2e_site($folder, 'r1-abort', ['scenario' => 'abort'], ['objects' => ['bytes' => 8000000000]]);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('exactly the limit passes', $exit === 0 && str_contains($output, '| normal |') && str_contains($output, 'r1-load')
    && str_contains($output, '30.000 GB'), "exit $exit: $output");
secbk_e2e_site($folder, 'r1-abort', ['scenario' => 'abort'], ['objects' => ['bytes' => 8000000001]]);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('one byte over the limit fails', $exit === 1 && str_contains($output, 'over the limit'), "exit $exit: $output");
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=31']);
secbk_e2e_expect('the limit is the option', $exit === 0, "exit $exit: $output");

// 4. summary.php fails when a scenario failed, or when the objects of a site are not all locked, or when the inspection of
// a site is missing or failed.
$cases = [
    'a scenario that failed' => [['passed' => false, 'reasons' => ['cli/backup.php --now ended with 1']], [],
        'cli/backup.php --now ended with 1'],
    'objects without a lock' => [[], ['lock' => ['compliance' => false, 'missing_keys' => 2]], 'missing 2'],
    'a lock that is not COMPLIANCE' => [[], ['lock' => ['compliance' => false]], 'not all locked'],
    'a lock that has expired' => [[], ['lock' => ['compliance' => false, 'expired_keys' => 1]], 'expired 1'],
    'an object that is gone' => [[], ['lock' => ['compliance' => false, 'gone_keys' => 1]], 'gone 1'],
    'missing keys alone' => [[], ['lock' => ['missing_keys' => 1]], 'missing 1'],
    'no inspection' => [[], null, 'no inspect.json'],
    'an inspection that could not read the locks' => [[], '{"error":"lock_read_failed","http_status":503}', 'lock_read_failed'],
    'an inspection of too many objects' => [[], '{"error":"too_many_objects","http_status":413}', 'too_many_objects'],
    'an inspection that is not JSON' => [[], 'not json', 'cannot be read'],
];
foreach ($cases as $label => [$result, $inspection, $words]) {
    $folder = "$work/results-" . md5($label);
    secbk_e2e_site($folder, 'r1-normal');
    secbk_e2e_site($folder, 'r1-load', ['scenario' => 'load'] + $result, $inspection);
    [$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
    secbk_e2e_expect("$label fails the summary", $exit === 1 && str_contains($output, $words) && str_contains($output, 'r1-load'),
        "exit $exit: $output");
}
$folder = "$work/results-good";
secbk_e2e_site($folder, 'r1-normal');
for ($n = 1; $n <= 10; $n++) {
    secbk_e2e_site($folder, sprintf('r1-c%02d', $n), ['scenario' => 'concurrent',
        'retries' => ['waits_without_failure' => 2, 'failed_steps' => 5]], ['objects' => ['bytes' => 900000000]]);
}
secbk_e2e_site($folder, 'r1-data', ['scenario' => 'data-only', 'sent_bytes' => 0], null);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('sites that all passed pass; the scenario data-only needs no inspection', $exit === 0
    && substr_count($output, '| concurrent |') === 10 && str_contains($output, '| data-only |')
    && str_contains($output, '9.000 GB'), "exit $exit: $output");
secbk_e2e_expect('the summary shows the waits without a failed step and the failed steps of the runs',
    str_contains($output, '| 2 | 5 |'), $output);
secbk_e2e_expect('the ten sites of concurrent ran together; the latest start is not known without the numbers',
    str_contains($output, 'Concurrent: the runs of the 10 sites ran together for 600 s (01:30:00 to 01:40:00 UTC); the latest'
    . ' start of a site is not known.'), $output);
secbk_e2e_expect('the summary does not show the IDs', !str_contains($output, '11111111-2222')
    && !str_contains($output, 'bbbbbbbb-2222'), $output);
@mkdir("$work/results-empty");
[$exit, $output] = secbk_e2e_run('summary.php', ["$work/results-empty", '--limit-gb=30']);
secbk_e2e_expect('a folder without results fails', $exit === 1 && str_contains($output, 'no result'), "exit $exit: $output");
$folder = "$work/results-result-missing";
secbk_e2e_site($folder, 'r1-normal');
unlink("$folder/r1-normal/result.json");
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('an inspection without result.json fails', $exit === 1 && str_contains($output, 'no result.json'),
    "exit $exit: $output");
[$exit, $output] = secbk_e2e_run('summary.php', [$folder]);
secbk_e2e_expect('summary.php without --limit-gb is refused', $exit === 2, "exit $exit: $output");
// The amount of a site whose inspection failed or is missing is not known: the table says so, and the total says how many.
$folder = "$work/results-unknown";
secbk_e2e_site($folder, 'r1-normal', [], ['objects' => ['bytes' => 2000000000]]);
secbk_e2e_site($folder, 'r1-load', ['scenario' => 'load'], '{"error":"lock_read_failed","http_status":503}');
secbk_e2e_site($folder, 'r1-data', ['scenario' => 'data-only', 'sent_bytes' => 0], null);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('an amount that is not known is shown as unknown, and counted in the total line', $exit === 1
    && substr_count($output, '| unknown |') === 1 && str_contains($output, '| data-only | r1-data | passed | 0.000 |')
    && str_contains($output, 'Sent in total: 2.000 GB') && str_contains($output, 'not known for 1 site'), "exit $exit: $output");
secbk_e2e_site($folder, 'r1-abort', ['scenario' => 'abort'], null);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('two sites without an amount', $exit === 1 && substr_count($output, '| unknown |') === 2
    && str_contains($output, 'not known for 2 sites'), "exit $exit: $output");
$folder = "$work/results-unknown-over";
secbk_e2e_site($folder, 'r1-normal', [], ['objects' => ['bytes' => 31000000000]]);
secbk_e2e_site($folder, 'r1-load', ['scenario' => 'load'], null);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('the limit is judged on the amounts that are known', $exit === 1 && str_contains($output, 'over the limit'),
    "exit $exit: $output");
// An amount that is not known has a problem of its own, whatever the reason: here an inspection whose bytes are not a number.
$folder = "$work/results-bytes-text";
secbk_e2e_site($folder, 'r1-normal', [], json_encode(array_merge(secbk_e2e_inspection(),
    ['objects' => array_merge(secbk_e2e_inspection()['objects'], ['bytes' => '5000'])])));
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('an amount that is not known fails, also without an error in the inspection', $exit === 1
    && str_contains($output, '| unknown |') && str_contains($output, 'r1-normal: the amount that the service holds is not known'),
    "exit $exit: $output");

// The Moodle of each site and whether the service had the official list of its files; a site that sent the code of Moodle is
// a note, which does not fail the summary.
$moodle = ['commit' => str_repeat('c', 40), 'version' => '2024100715.01', 'release' => '4.5.15+ (Build: 20261021)',
    'build' => '20261021'];
$folder = "$work/results-moodle";
secbk_e2e_site($folder, 'r1-normal', ['moodle' => $moodle + ['core_manifest' => true]]);
secbk_e2e_site($folder, 'r1-load', ['scenario' => 'load', 'moodle' => $moodle + ['core_manifest' => false]]);
secbk_e2e_site($folder, 'r1-abort', ['scenario' => 'abort', 'moodle' => $moodle + ['core_manifest' => false]]);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('the summary shows the Moodle of each site and whether its official list was used', $exit === 0
    && str_contains($output, '| 4.5.15+ (Build: 20261021), list used |')
    && substr_count($output, '| 4.5.15+ (Build: 20261021), code sent |') === 2, "exit $exit: $output");
secbk_e2e_expect('the sites that sent the code of Moodle are one note, which does not fail the summary', $exit === 0
    && str_contains($output, '**Notes**') && str_contains($output, 'sent the code of Moodle (abort r1-abort, load r1-load): the'
    . ' service has no official list of the files of Build 20261021 (.dev/e2e/README.md)') && str_contains($output, '**Passed.**'),
    "exit $exit: $output");

// concurrent: how long the runs of the ten sites ran together (from the latest start to the earliest end, by the times of
// the service), and how late the latest site started. Runs that never ran together fail; a late site is a note.
// The times of the start and of the end of the run count; created_at and updated_at are other times here, so that a summary
// that used them would show other numbers.
$concurrentrun = static fn(string $from, string $to, array $changes = []): array => ['runs' => [array_merge([
    'uuid' => 'bbbbbbbb-2222-4333-8444-555555555555', 'status' => 'completed', 'failure_code' => null,
    'requested_bytes' => 3000, 'has_receipt' => true, 'started_at' => "2026-10-23T$from+00:00",
    'completed_at' => "2026-10-23T$to+00:00", 'failed_at' => null, 'created_at' => '2026-10-23T00:00:00+00:00',
    'updated_at' => '2026-10-23T02:00:00+00:00'], $changes)]];
$folder = "$work/results-together";
secbk_e2e_site($folder, 'r1-c01', ['scenario' => 'concurrent', 'numbers' => ['start_late' => 0]],
    $concurrentrun('01:00:00', '01:20:00'));
secbk_e2e_site($folder, 'r1-c02', ['scenario' => 'concurrent', 'numbers' => ['start_late' => 0]],
    $concurrentrun('01:05:00', '01:15:00'));
secbk_e2e_site($folder, 'r1-c03', ['scenario' => 'concurrent', 'numbers' => ['start_late' => 45]],
    $concurrentrun('01:10:00', '01:30:00'));
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('concurrent: the time that the runs ran together, and the latest start', $exit === 0
    && str_contains($output, 'Concurrent: the runs of the 3 sites ran together for 300 s (01:10:00 to 01:15:00 UTC); the latest'
    . ' site started 45 s after the start of the ten sites.'), "exit $exit: $output");
secbk_e2e_expect('concurrent: a site that started late is a note', $exit === 0
    && str_contains($output, 'concurrent r1-c03: started 45 s after the start of the ten sites'), "exit $exit: $output");
secbk_e2e_site($folder, 'r1-c04', ['scenario' => 'concurrent', 'numbers' => ['start_late' => 0]],
    $concurrentrun('01:16:00', '01:40:00'));
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('concurrent: runs that never ran together fail', $exit === 1
    && str_contains($output, 'the runs of the 4 sites of concurrent never ran together: the latest started at 01:16:00, after'
    . ' the earliest ended at 01:15:00 (UTC)'), "exit $exit: $output");
// Times with different offsets (the service may give them in the time zone of Japan) are compared as instants.
$folder = "$work/results-together-offsets";
secbk_e2e_site($folder, 'r1-c01', ['scenario' => 'concurrent'], $concurrentrun('01:00:00', '01:20:00',
    ['started_at' => '2026-10-23T10:00:00+09:00']));
secbk_e2e_site($folder, 'r1-c02', ['scenario' => 'concurrent'], $concurrentrun('01:05:00', '01:00:00',
    ['completed_at' => '2026-10-23T10:15:00+09:00']));
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('concurrent: times in +09:00 and +00:00 are compared as instants', $exit === 0
    && str_contains($output, 'Concurrent: the runs of the 2 sites ran together for 600 s (01:05:00 to 01:15:00 UTC)'),
    "exit $exit: $output");
// A run that failed ends at failed_at.
$folder = "$work/results-together-failed";
secbk_e2e_site($folder, 'r1-c01', ['scenario' => 'concurrent'], $concurrentrun('01:00:00', '01:20:00'));
secbk_e2e_site($folder, 'r1-c02', ['scenario' => 'concurrent'], $concurrentrun('01:05:00', '01:00:00',
    ['status' => 'failed', 'failure_code' => 'upload_failed', 'completed_at' => null, 'failed_at' => '2026-10-23T01:12:00+00:00']));
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('concurrent: a run that failed ends at failed_at', str_contains($output, 'Concurrent: the runs of the 2 sites'
    . ' ran together for 420 s (01:05:00 to 01:12:00 UTC)'), "exit $exit: $output");
// Without started_at, completed_at and failed_at, created_at and updated_at stand in for them, and the site has that problem:
// updated_at moves on with writes after the end.
$folder = "$work/results-together-substitute";
secbk_e2e_site($folder, 'r1-c01', ['scenario' => 'concurrent'], $concurrentrun('01:00:00', '01:20:00'));
secbk_e2e_site($folder, 'r1-c02', ['scenario' => 'concurrent'], $concurrentrun('01:00:00', '01:00:00',
    ['started_at' => null, 'completed_at' => null, 'created_at' => '2026-10-23T01:05:00+00:00',
        'updated_at' => '2026-10-23T01:15:00+00:00']));
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('concurrent: the times that stand in for missing ones are used, and said', $exit === 1
    && str_contains($output, 'ran together for 600 s (01:05:00 to 01:15:00 UTC)')
    && str_contains($output, 'concurrent r1-c02: the service gave no started_at of its last run: created_at is used instead')
    && str_contains($output, 'concurrent r1-c02: the service gave neither completed_at nor failed_at of its last run: updated_at'
    . ' is used instead, which later writes move on'), "exit $exit: $output");
$folder = "$work/results-together-unknown";
secbk_e2e_site($folder, 'r1-c01', ['scenario' => 'concurrent'], $concurrentrun('01:00:00', '01:20:00'));
secbk_e2e_site($folder, 'r1-c02', ['scenario' => 'concurrent'], $concurrentrun('01:00:00', '01:00:00', ['started_at' => null,
    'completed_at' => null, 'created_at' => null, 'updated_at' => null]));
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('concurrent: a site whose run has no times fails, since the overlap cannot be known', $exit === 1
    && str_contains($output, 'concurrent r1-c02: the service gave no time of the start or of the end of its last run'),
    "exit $exit: $output");

// The waits of the runs that no failed step comes before, but in the stage of the receipt (the requests that the service
// answered as busy are among them, and the sends to S3 that are tried again), and the failed steps, in a column of their own.
$folder = "$work/results-waits";
secbk_e2e_site($folder, 'r1-normal', ['retries' => ['waits_without_failure' => 3, 'failed_steps' => 4]]);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('the summary shows the waits without a failed step and the failed steps', $exit === 0
    && str_contains($output, '| Waits without a failed step | Failed steps |') && str_contains($output, '| 3 | 4 |'),
    "exit $exit: $output");
secbk_e2e_expect('the summary says what the waits without a failed step count', str_contains($output, 'the requests that the'
    . ' service answered as busy (429, 503)') && str_contains($output, 'the sends to S3 that are tried again'), $output);

// verdict.php: the conditions of the scenarios on the answer of the inspection.
$good = secbk_e2e_inspection();
$failedrun = ['uuid' => 'aaaaaaaa-2222-4333-8444-555555555555', 'status' => 'failed', 'failure_code' => 'server_busy',
    'requested_bytes' => 4000, 'has_receipt' => false, 'created_at' => null, 'updated_at' => null];
$openrun = ['status' => 'uploading', 'failure_code' => null, 'has_receipt' => false] + $failedrun;
$completed = $good['runs'][0];
$verdicts = [
    'one completed run with a receipt' => [$good, ['--done', '--runs=1'], 0, 'verdict: pass'],
    'a completed run without a receipt' => [secbk_e2e_inspection(['runs' => [['has_receipt' => false] + $completed]]),
        ['--done', '--runs=1'], 1, 'reason: the last run is completed without a receipt'],
    'a run that failed where one completed run was expected' => [secbk_e2e_inspection(['runs' => [$failedrun]]),
        ['--done', '--runs=1'], 1, 'reason: the last run is failed (server_busy)'],
    'one run where two were expected' => [$good, ['--done', '--runs=2', '--failed=server_busy'], 1,
        'reason: expected 2 runs, found 1'],
    'a stop and a completed run' => [secbk_e2e_inspection(['runs' => [$failedrun, $completed]]),
        ['--done', '--runs=2', '--failed=server_busy'], 0, 'verdict: pass'],
    'a stop for another reason' => [secbk_e2e_inspection(['runs' => [$failedrun, $completed]]),
        ['--done', '--runs=2', '--failed=upload_failed'], 1,
        'reason: run 1 is failed (server_busy), expected failed (upload_failed)'],
    'two completed generations' => [secbk_e2e_inspection(['runs' => [$completed, $completed]]), ['--done', '--runs=2'], 1,
        'reason: expected one completed generation, found 2'],
    'an object in another storage class' => [secbk_e2e_inspection(['objects' => ['storage_classes' =>
        ['INTELLIGENT_TIERING' => 11, 'STANDARD' => 1]]]), ['--done', '--runs=1'], 1,
        'reason: objects not in INTELLIGENT_TIERING: STANDARD 1'],
    'an object without a lock' => [secbk_e2e_inspection(['lock' => ['compliance' => false, 'missing_keys' => 1]]),
        ['--done', '--runs=1'], 1, 'reason: not all objects are locked in COMPLIANCE mode (missing 1, expired 0, gone 0)'],
    'no object' => [secbk_e2e_inspection(['objects' => ['count' => 0, 'bytes' => 0, 'storage_classes' => []]]),
        ['--done', '--runs=1'], 1, 'reason: the service holds no object of the site'],
    'a run still open after a kill' => [secbk_e2e_inspection(['runs' => [$openrun]]), ['--stopped=open'], 0, 'verdict: pass'],
    'a completed run after a kill' => [$good, ['--stopped=open'], 1, 'reason: a run completed'],
    'a stop with the expected reason' => [secbk_e2e_inspection(['runs' => [$failedrun]]), ['--stopped=server_busy'], 0,
        'verdict: pass'],
    'a run that is still open where a stop was expected' => [secbk_e2e_inspection(['runs' => [$openrun]]),
        ['--stopped=server_busy'], 1, 'reason: the last run is uploading, expected failed (server_busy)'],
    'an unlocked object after a stop' => [secbk_e2e_inspection(['runs' => [$failedrun], 'lock' => ['compliance' => false,
        'missing_keys' => 3]]), ['--stopped=server_busy'], 1, 'reason: not all objects are locked'],
    'an inspection that failed' => ['{"error":"lock_read_failed","http_status":503}', ['--done', '--runs=1'], 1,
        'reason: the inspection failed (lock_read_failed)'],
];
foreach ($verdicts as $label => [$inspection, $args, $wanted, $words]) {
    $file = secbk_e2e_inspect_file('verdict-' . md5($label) . '.json', $inspection);
    [$exit, $output] = secbk_e2e_run('verdict.php', array_merge([$file], $args));
    secbk_e2e_expect("verdict: $label", $exit === $wanted && str_contains($output, $words), "exit $exit: $output");
}
[$exit, $output] = secbk_e2e_run('verdict.php', [secbk_e2e_inspect_file('verdict-usage.json', $good)]);
secbk_e2e_expect('verdict.php without a mode is refused', $exit === 2, "exit $exit: $output");
[$exit, $output] = secbk_e2e_run('verdict.php', ["$work/no-such-file.json", '--done', '--runs=1']);
secbk_e2e_expect('verdict.php fails on a missing file', $exit === 1 && str_contains($output, 'reason: '), "exit $exit: $output");

// lib.sh: e2e_config puts the address of the site and the key of the service in config.php, in the place of the settings of
// the fake service that storage_install writes, and changes nothing else; a second call replaces its own block.
$fakeblock = "// tool_secbackup development settings: the fake backup service.\ndefine('TOOL_SECBACKUP_DEVELOPER', [\n"
    . "    'trustedkeys' => ['PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw='],\n    'allowhttp' => true,\n"
    . "    'allowprivatehosts' => true,\n    'skipnetworkchecks' => true,\n]);\n";
$config = "<?php  // Moodle configuration file\n\nunset(\$CFG);\nglobal \$CFG;\n\$CFG = new stdClass();\n\n"
    . "\$CFG->dbtype    = 'pgsql';\n\$CFG->wwwroot   = 'http://localhost:8000';\n\$CFG->dataroot  = '/tmp/moodledata';\n\n"
    . "// No mail leaves this machine; the dummy users get an address that is valid.\n\$CFG->noemailever = true;\n"
    . $fakeblock . "\nrequire_once(__DIR__ . '/lib/setup.php');\n";
$key = base64_encode(str_repeat("\x22", 32));
$key2 = base64_encode(str_repeat("\x33", 32));
$expected = str_replace(["\$CFG->wwwroot   = 'http://localhost:8000';", $fakeblock], [
    "\$CFG->wwwroot = 'https://r1-normal.moodle.example.invalid';",
    "// tool_secbackup remote service settings (written by .dev/e2e/lib.sh).\ndefine('TOOL_SECBACKUP_DEVELOPER', [\n"
        . "    'trustedkeys' => ['$key2'],\n]);\n// End of tool_secbackup remote service settings.\n",
], $config);
$configfile = "$work/storage/moodle/config.php";
@mkdir(dirname($configfile), 0777, true);
file_put_contents($configfile, $config);
[$exit, $output] = secbk_e2e_lib('e2e_config wwwroot https://r1-normal.moodle.example.invalid; e2e_config service "$E2E_KEY";'
    . ' e2e_config service "$E2E_KEY2"', ['E2E_KEY' => $key, 'E2E_KEY2' => $key2]);
$text = (string) file_get_contents($configfile);
secbk_e2e_expect('e2e_config replaces the address and the settings of the fake service, and nothing else', $exit === 0
    && $text === $expected, "exit $exit: $output\n$text");
secbk_e2e_expect('e2e_config leaves one definition of the settings', substr_count($text, "define('TOOL_SECBACKUP_DEVELOPER'") === 1,
    $text);
$refusals = [
    'a key that is not base64' => 'e2e_config service not-a-key',
    'a key of 31 bytes' => 'e2e_config service "$E2E_SHORT"',
    'an address over http' => 'e2e_config wwwroot http://r1-normal.moodle.example.invalid',
    'a setting that it does not know' => 'e2e_config dataroot /tmp',
];
foreach ($refusals as $label => $code) {
    [$exit, $output] = secbk_e2e_lib($code, ['E2E_SHORT' => base64_encode(str_repeat("\x22", 31))]);
    secbk_e2e_expect("e2e_config refuses $label and leaves config.php as it was", $exit !== 0
        && file_get_contents($configfile) === $expected, "exit $exit: $output");
}
file_put_contents($configfile, str_replace($fakeblock, '', $config));
[$exit, $output] = secbk_e2e_lib('e2e_config service "$E2E_KEY"', ['E2E_KEY' => $key]);
secbk_e2e_expect('e2e_config fails on a config.php without the settings', $exit !== 0
    && file_get_contents($configfile) === str_replace($fakeblock, '', $config), "exit $exit: $output");

// lib.sh: the check of the disk reads numbers, or stops with a reason.
$disks = [
    'unreadable' => ["printf 'Avail Size\\nx y\\n'", 1, 'the free space of the disk of the site cannot be read'],
    'empty' => ['true', 1, 'the free space of the disk of the site cannot be read'],
    'too small' => ["printf 'Avail Size\\n1000 100000000000\\n'", 1, 'the disk has 1000 bytes free, 14000000000 are needed'],
    'enough' => ["printf 'Avail Size\\n100000000000 200000000000\\n'", 0, null],
];
foreach ($disks as $label => [$df, $wanted, $words]) {
    $name = 'r1-disk-' . str_replace(' ', '-', $label);
    [$exit, $output] = secbk_e2e_lib("e2e_begin normal $name; df() { $df; }; e2e_check_disk 1; e2e_finish");
    $reasons = secbk_e2e_result($name)['reasons'] ?? ['no result.json'];
    secbk_e2e_expect("the check of the disk: $label", $exit === $wanted
        && ($words === null ? $reasons === [] : str_contains(implode("\n", $reasons), $words)), "exit $exit: $output");
}

// lib.sh: the check of a resend counts the bytes counted twice, sent + before - after, and allows a tenth of before.
$resends = [
    'no resend' => [1000, 1900, 3000, null],
    'a tenth counted twice' => [1000, 1100, 2000, null],
    'more than a tenth counted twice' => [1000, 1101, 2000, 'the next run sent again about 101 of the 1000 bytes'],
    'nothing at the service after the stop' => [0, 10, 10, 'the service held nothing of the site after the stop'],
];
foreach ($resends as $label => [$before, $sent, $after, $words]) {
    $name = 'r1-resend-' . md5($label);
    [$exit, $output] = secbk_e2e_lib("e2e_begin load $name; e2e_check_resend $before $sent $after; e2e_finish");
    $result = secbk_e2e_result($name) ?? [];
    secbk_e2e_expect("the check of a resend: $label", ($result['numbers'] ?? null) === ['s3_bytes_after_stop' => $before,
        'next_run_sent_bytes' => $sent, 's3_bytes_at_end' => $after] && ($words === null ? $exit === 0 && $result['passed'] === true
        : $exit === 1 && str_contains(implode("\n", $result['reasons'] ?? []), $words)), "exit $exit: $output");
}

// lib.sh: the result reads the numbers (names with digits too), the durations, the lines of the output of cli/backup.php, and
// the amount and the requests of the runs from inspect.json; a script that stops names its step.
$inspection = json_encode(secbk_e2e_inspection(['runs' => [$failedrun, $completed], 'objects' => ['bytes' => 4242]]));
[$exit, $output] = secbk_e2e_lib('e2e_begin load r1-result; e2e_number s3_bytes_after_stop 1000; e2e_number start_late 0;'
    . ' e2e_duration build $(( $(date +%s) - 5 ));'
    // A wait that no failed step comes before (counted), the wait after a failed step (not counted: the failed steps are
    // counted on their own), a wait in the stage of the receipt (not counted), and a failed step without a wait, after which
    // the next pass of the command waits again (counted).
    . ' printf "Stage: Sending the database\nWaiting until a\nA step of the backup failed (plugin_exception temp_write_failed).'
    . ' It is tried again in 5 minutes.\nStage: Sending the files of the site\nWaiting until b\nStage: Waiting for the receipt'
    . ' of the backup service\nWaiting until c\n" > "$e2e_logs/backup-1.log"; printf "A step of the backup failed (x). It is'
    . ' tried again in 5 minutes.\nStage: Sending the files of the site\nStage: Sending the files of the site\nWaiting until'
    . ' d\n" > "$e2e_logs/backup-2.log";'
    . ' printf "%s" "$E2E_INSPECT" > "$e2e_folder/inspect.json"; e2e_finish', ['E2E_INSPECT' => $inspection]);
$result = secbk_e2e_result('r1-result') ?? [];
secbk_e2e_expect('the result of a scenario that passed', $exit === 0 && ($result['scenario'] ?? '') === 'load'
    && ($result['name'] ?? '') === 'r1-result' && ($result['passed'] ?? null) === true && ($result['reasons'] ?? null) === []
    && ($result['sent_bytes'] ?? null) === 4242
    && ($result['retries'] ?? null) === ['waits_without_failure' => 2, 'failed_steps' => 2]
    && array_key_exists('moodle', $result) && $result['moodle'] === null
    && ($result['numbers'] ?? null) === ['s3_bytes_after_stop' => 1000, 'start_late' => 0]
    && ($result['requested_bytes'] ?? null) === [4000, 3000] && ($result['durations']['build'] ?? 0) >= 5
    && isset($result['durations']['total']), "exit $exit: $output " . json_encode($result));
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-stopped; e2e_step "a step that fails"; false; e2e_finish');
$result = secbk_e2e_result('r1-stopped') ?? [];
secbk_e2e_expect('the result of a script that stopped', $exit === 1 && ($result['passed'] ?? null) === false
    && ($result['reasons'] ?? null) === ['the script stopped (exit 1) at the step: a step that fails']
    && ($result['sent_bytes'] ?? null) === 0, "exit $exit: $output " . json_encode($result));

// lib.sh: the result keeps the Moodle of the site (logs/moodle.json, written when the site is built) and whether its run
// used the official list of the files of that build (logs/core.txt, read from the record of the run at the end).
$moodle = ['commit' => str_repeat('c', 40), 'version' => '2024100715.01', 'release' => '4.5.15+ (Build: 20261021)',
    'build' => '20261021'];
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-moodle; printf "%s" "$E2E_MOODLE" > "$e2e_logs/moodle.json";'
    . ' echo 0 > "$e2e_logs/core.txt"; e2e_finish', ['E2E_MOODLE' => json_encode($moodle)]);
$result = secbk_e2e_result('r1-moodle') ?? [];
secbk_e2e_expect('the result keeps the Moodle of the site and whether the official list was used', $exit === 0
    && ($result['moodle'] ?? null) === $moodle + ['core_manifest' => false], "exit $exit: $output " . json_encode($result));
// The record of the runs, newest first: the latest run that compared the code with the official list says whether it had it.
$cores = [
    'the latest comparison had no list' => [['{"run":{"stored_bytes":5}}', '{"run":{"core":{"official":false}}}',
        '{"run":{"core":{"official":true}}}'], '0'],
    'the latest comparison had the list' => [['{"run":{"core":{"official":true}}}'], '1'],
    'no run compared' => [['{"run":{}}', ''], null],
];
foreach ($cores as $label => [$rows, $wanted]) {
    $name = 'r1-core-' . md5($label);
    $list = implode(' ', array_map('escapeshellarg', $rows));
    [$exit, $output] = secbk_e2e_lib("e2e_begin normal $name; psql() { printf '%s\\n' $list; }; e2e_read_core; e2e_finish");
    $core = @file_get_contents("$work/out/$name/logs/core.txt");
    secbk_e2e_expect("the use of the official list: $label", $exit === 0
        && ($wanted === null ? $core === false : trim((string) $core) === $wanted),
        "exit $exit: $output " . var_export($core, true));
}
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-core-db; psql() { return 2; }; e2e_read_core; e2e_finish');
secbk_e2e_expect('the use of the official list is not known when the database cannot be read, which is no reason to fail',
    $exit === 0 && !is_file("$work/out/r1-core-db/logs/core.txt"), "exit $exit: $output");

// lib.sh: the Moodle of the sites is fixed by moodle.json: a branch, a commit of 40 hexadecimal digits, and the $version and
// $release that the code of that commit has; the check after the fetch compares all three with the code.
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-pin; e2e_moodle_pin;'
    . ' echo "pin $e2e_moodle_branch $e2e_moodle_commit $e2e_moodle_version $e2e_moodle_release"; e2e_finish');
secbk_e2e_expect('moodle.json fixes a commit of MOODLE_405_STABLE', $exit === 0
    && preg_match('/^pin MOODLE_405_STABLE [0-9a-f]{40} [0-9]{10}\.[0-9]{2} 4\.5\.[0-9]+\+? \(Build: [0-9]{8}\)$/m', $output) === 1,
    "exit $exit: $output");
$pin = ['branch' => 'MOODLE_405_STABLE', 'commit' => str_repeat('a', 40), 'version' => '2024100715.01',
    'release' => '4.5.15+ (Build: 20261021)'];
$badpins = [
    'a short commit' => array_merge($pin, ['commit' => 'abc123']),
    'a commit in upper case' => array_merge($pin, ['commit' => str_repeat('A', 40)]),
    'a branch that is not a stable branch' => array_merge($pin, ['branch' => 'main']),
    'a version that is not one' => array_merge($pin, ['version' => '2024100715']),
    'a release without its build' => array_merge($pin, ['release' => '4.5.15+']),
    'a key that it does not know' => $pin + ['tag' => 'v4.5.15'],
    'the keys in another order' => ['commit' => $pin['commit']] + $pin,
    'no release' => array_diff_key($pin, ['release' => true]),
    'not JSON' => 'not json',
];
foreach ($badpins as $label => $value) {
    $file = secbk_e2e_inspect_file('pin-' . md5($label) . '.json', is_string($value) ? $value : json_encode($value));
    $name = 'r1-pin-' . md5($label);
    [$exit, $output] = secbk_e2e_lib("e2e_begin normal $name; e2e_moodle_pin " . escapeshellarg($file) . '; e2e_finish');
    secbk_e2e_expect("moodle.json with $label is refused", $exit === 1
        && str_contains(implode("\n", secbk_e2e_result($name)['reasons'] ?? []), 'moodle.json does not fix'),
        "exit $exit: $output");
}
// A git repository with the version.php of the build in the place of the code of Moodle.
$pinstorage = "$work/pin-storage";
@mkdir("$pinstorage/moodle", 0777, true);
file_put_contents("$pinstorage/moodle/version.php", "<?php\ndefined('MOODLE_INTERNAL') || die();\n\n"
    . "\$version  = 2024100715.01;              // 20241007      = branching date YYYYMMDD - do not modify!\n"
    . "\$release  = '4.5.15+ (Build: 20261021)';    // Human-friendly version name\n\$branch   = '405';\n");
$git = 'git -C ' . escapeshellarg("$pinstorage/moodle") . ' -c core.hooksPath=/dev/null -c commit.gpgsign=false'
    . ' -c user.name=test -c user.email=test@example.invalid';
// Development tooling may run commands; the plugin itself never does.
\exec("$git init -q && $git add version.php && $git commit -q -m build && $git rev-parse HEAD", $gitout, $gitexit);
$head = (string) end($gitout);
$checks = [
    'the code of the commit and the version it fixes' => [array_merge($pin, ['commit' => $head]), null],
    'another commit' => [array_merge($pin, ['commit' => str_repeat('b', 40)]), "the code of Moodle is at commit $head,"
        . ' moodle.json fixes ' . str_repeat('b', 40)],
    'another release' => [array_merge($pin, ['commit' => $head, 'release' => '4.5.14+ (Build: 20261014)']),
        "has \$version 2024100715.01 and \$release 4.5.15+ (Build: 20261021), moodle.json says 2024100715.01 and"
        . ' 4.5.14+ (Build: 20261014)'],
];
foreach ($checks as $label => [$value, $words]) {
    $file = secbk_e2e_inspect_file('check-' . md5($label) . '.json', json_encode($value));
    $name = 'r1-check-' . md5($label);
    [$exit, $output] = secbk_e2e_lib("e2e_begin normal $name; e2e_moodle_pin " . escapeshellarg($file)
        . '; e2e_check_moodle; e2e_finish', ['SECBK_STORAGE_DIR' => $pinstorage]);
    $result = secbk_e2e_result($name) ?? [];
    secbk_e2e_expect("the check of the code of Moodle: $label", $gitexit === 0 && ($words === null
        ? $exit === 0 && ($result['moodle'] ?? null) === ['commit' => $head, 'version' => '2024100715.01',
            'release' => '4.5.15+ (Build: 20261021)', 'build' => '20261021', 'core_manifest' => null]
        : $exit === 1 && str_contains(implode("\n", $result['reasons'] ?? []), $words)),
        "exit $exit: $output " . json_encode($result));
}

// lib.sh: a scenario in two parts, the site (no secret) and then the scenario (the service), each its own step of the job.
// After the site, the result does not pass yet and says why, but the script ends with 0; the scenario goes on with the same
// reasons, durations and numbers, and its total counts from the start of the site.
// The site part started 10 s ago (its start is in logs/started.txt).
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-parts site; echo $(( $(date +%s) - 10 )) > "$e2e_logs/started.txt";'
    . ' e2e_duration build $(( $(date +%s) - 5 ));'
    . ' e2e_number start_late 0; : > "$e2e_logs/site.done"; e2e_pending; e2e_finish');
$result = secbk_e2e_result('r1-parts') ?? [];
secbk_e2e_expect('the site part: the result says that the scenario has not run, and the step ends with 0', $exit === 0
    && ($result['passed'] ?? null) === false && ($result['reasons'] ?? null) === ['the scenario has not run yet: only the site'
    . ' was built (run.sh --part=scenario runs it)'] && str_contains($output, 'the scenario runs in the next step'),
    "exit $exit: $output " . json_encode($result));
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-parts scenario; e2e_require_site; e2e_duration backup $(( $(date +%s) - 3 ));'
    . ' e2e_finish');
$result = secbk_e2e_result('r1-parts') ?? [];
secbk_e2e_expect('the scenario part goes on with the result of the site part', $exit === 0 && ($result['passed'] ?? null) === true
    && ($result['reasons'] ?? null) === [] && ($result['durations']['build'] ?? 0) >= 5
    && ($result['durations']['backup'] ?? 0) >= 3
    && ($result['durations']['total'] ?? 0) >= 10 && ($result['numbers'] ?? null) === ['start_late' => 0],
    "exit $exit: $output " . json_encode($result));
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-parts-failed site; e2e_step "the extra plugins"; false; e2e_finish');
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-parts-failed scenario; e2e_require_site; e2e_finish');
$result = secbk_e2e_result('r1-parts-failed') ?? [];
secbk_e2e_expect('the scenario part of a site that was not built stops, and keeps the reason of the site', $exit === 1
    && ($result['reasons'] ?? null) === ['the script stopped (exit 1) at the step: the extra plugins',
    'the site was not built: run.sh --part=site comes first', 'the script stopped (exit 1) at the step: the start'],
    "exit $exit: $output " . json_encode($result));

/**
 * The command lines of the processes of this machine that match a pattern.
 *
 * @param string $pattern A regular expression on the command line.
 * @return string[]
 */
function secbk_e2e_processes(string $pattern): array {
    $lines = [];
    // Development tooling may run commands; the plugin itself never does.
    \exec('ps -A -o command=', $lines);
    return array_values(array_filter($lines, static fn(string $line): bool => preg_match($pattern, trim($line)) === 1));
}

/**
 * Runs bash code after lib.sh is sourced, sends it SIGTERM once its output shows a line, and returns [seconds until it
 * ended, its output].
 *
 * @param string $code The code.
 * @param string $line The line to wait for before the signal.
 * @param array $env Variables of the environment to set.
 * @return array
 */
function secbk_e2e_terminate(string $code, string $line, array $env = []): array {
    global $e2e, $work;
    $out = "$work/term-" . md5($code) . '.out';
    $started = microtime(true);
    // Development tooling may run commands; the plugin itself never does.
    $process = proc_open(['bash', '-c', 'set -euo pipefail; . "$0/lib.sh"; ' . $code, $e2e], [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $out, 'a'], 2 => ['file', $out, 'a']], $pipes, null, array_merge(getenv(),
        ['SECBK_STORAGE_DIR' => "$work/storage", 'SECBK_E2E_OUT' => "$work/out"], $env));
    for ($i = 0; $i < 100 && !str_contains((string) @file_get_contents($out), $line); $i++) {
        usleep(100000);
    }
    usleep(500000);
    proc_terminate($process, 15);
    proc_close($process);
    return [microtime(true) - $started, (string) @file_get_contents($out)];
}

/**
 * Waits up to two seconds until no process matches a pattern, and returns the ones that are left.
 *
 * @param string $pattern A regular expression on the command line.
 * @return string[]
 */
function secbk_e2e_left(string $pattern): array {
    for ($i = 0; $i < 20 && secbk_e2e_processes($pattern); $i++) {
        usleep(100000);
    }
    return secbk_e2e_processes($pattern);
}

// lib.sh: a signal (a cancelled job) during a long wait runs the trap at once, which writes the result and stops what the
// script runs in the background: the sleep of the wait, and sec.php that waits for the service.
// The length of the sleep is made for this run, so that a process of another run cannot be taken for it.
$sleeplength = 600000 + getmypid() % 100000;
[$seconds, $output] = secbk_e2e_terminate("e2e_begin normal r1-term; e2e_step 'a long wait'; e2e_sleep $sleeplength; e2e_finish",
    'a long wait');
$result = secbk_e2e_result('r1-term') ?? [];
secbk_e2e_expect('a signal during a long wait writes the result at once', $seconds < 15
    && ($result['reasons'] ?? null) === ['the script stopped (exit 143) at the step: a long wait'],
    sprintf('%.1f s: ', $seconds) . $output . json_encode($result));
$left = secbk_e2e_left("~^sleep $sleeplength\$~");
secbk_e2e_expect('a signal during a long wait stops its sleep', $left === [], implode("\n", $left));
// A service that takes the connection and never answers: sec.php waits for it.
$silent = stream_socket_server('tcp://127.0.0.1:0');
$silentname = (string) stream_socket_get_name($silent, false);
$silentport = (int) substr($silentname, strrpos($silentname, ':') + 1);
[$seconds, $output] = secbk_e2e_terminate('e2e_begin normal r1-term-sec; e2e_site=11111111-2222-4333-8444-5555555590ff;'
    . ' e2e_step "a long request"; e2e_sec inspect --site="$e2e_site" --out="$e2e_logs/silent.json"; e2e_finish',
    'a long request', ['SECBK_E2E_BASE_URL' => "http://127.0.0.1:$silentport/api/lms-backup", 'SECBK_E2E_SECRET' => $secret]);
$result = secbk_e2e_result('r1-term-sec') ?? [];
secbk_e2e_expect('a signal during a request to the service writes the result at once', $seconds < 15
    && ($result['reasons'] ?? null) === ['the script stopped (exit 143) at the step: a long request'],
    sprintf('%.1f s: ', $seconds) . $output . json_encode($result));
$left = secbk_e2e_left('~/sec\.php inspect .*' . preg_quote($work, '~') . '~');
secbk_e2e_expect('a signal during a request to the service stops sec.php', $left === [], implode("\n", $left));
fclose($silent);

// lib.sh: the database of the site that cannot be read stops the scenario with that reason, also in a wait.
[$exit, $output] = secbk_e2e_lib('echo "timeout $PGCONNECT_TIMEOUT"');
secbk_e2e_expect('psql gives up a connection after 10 seconds', str_contains($output, 'timeout 10'), "exit $exit: $output");
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-db; psql() { echo "no such database" >&2; return 2; }; e2e_check_db;'
    . ' e2e_finish');
secbk_e2e_expect('a database that cannot be read stops the scenario', $exit === 1
    && str_contains(implode("\n", secbk_e2e_result('r1-db')['reasons'] ?? []), 'the database of the site cannot be read'),
    "exit $exit: $output");
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-dbwait; psql() { return 2; }; sleep 30 & e2e_pid=$!;'
    . ' if e2e_wait_sending 10; then echo reached; fi; kill "$e2e_pid"; e2e_finish');
secbk_e2e_expect('a database that cannot be read ends a wait with that reason', $exit === 1 && !str_contains($output, 'reached')
    && str_contains(implode("\n", secbk_e2e_result('r1-dbwait')['reasons'] ?? []), 'the database of the site cannot be read'),
    "exit $exit: $output");

// lib.sh: the wait for the point of a scenario looks only at the run of the command it waits for, never at a run from before
// it. The real e2e_backup_start starts the command (a php on the PATH turns cli/backup.php into a sleep), and psql gives the
// record of the latest run from a list, one row a call and the last one after it, as the database would show it while the
// command starts and writes the record of its run.
@mkdir("$work/bin", 0777, true);
@mkdir("$work/storage/moodle", 0777, true);
$commandlength = 700000 + getmypid() % 100000;
file_put_contents("$work/bin/php", "#!/bin/sh\ncase \"\$*\" in\n    *cli/backup.php*) exec sleep $commandlength ;;\nesac\nexec "
    . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
chmod("$work/bin/php", 0755);
$rowpsql = <<<'BASH'
e2e_test_count="$SECBK_E2E_OUT/psql-$E2E_NAME.count"
psql() {
    local n
    n=$(( $(cat "$e2e_test_count" 2> /dev/null || echo 0) + 1 ))
    echo "$n" > "$e2e_test_count"
    if [ "$n" -gt "${#e2e_test_rows[@]}" ]; then n="${#e2e_test_rows[@]}"; fi
    printf '%s\n' "${e2e_test_rows[$(( n - 1 ))]}"
}
BASH;
$waits = [
    'a --now after a run that S3 stopped in the stage files' => [['1|files|aborted|upload_failed|1200|10000',
        '1|files|aborted|upload_failed|1200|10000', '1|files|aborted|upload_failed|1200|10000', '2|prepare|active||0|0',
        '2|files|active||500|10000', '2|files|active||1500|10000'], '--now', 'reached run=2 bytes=1500 status=active'],
    'a --now after a run that completed' => [['1|done|completed||9000|10000', '1|done|completed||9000|10000',
        '2|db|active||100|0', '2|files|active||1500|10000'], '--now', 'reached run=2 bytes=1500 status=active'],
    'a --now whose run stops before the point' => [['1|files|aborted|upload_failed|1200|10000',
        '1|files|aborted|upload_failed|1200|10000', '2|files|aborted|server_busy|100|10000'], '--now', 'not reached'],
    'the command without --now that goes on with the run' => [['2|files|active||500|10000', '2|files|active||500|10000',
        '2|files|active||1500|10000'], '', 'reached run=2 bytes=1500 status=active'],
];
foreach ($waits as $label => [$rows, $now, $words]) {
    $name = 'r1-sending-' . md5($label);
    $list = implode(' ', array_map('escapeshellarg', $rows));
    [$exit, $output] = secbk_e2e_lib("$rowpsql\ne2e_test_rows=($list)\ne2e_begin abort $name\n"
        . "e2e_backup_start \"\$e2e_logs/backup-1.log\" $now\n"
        . 'if e2e_wait_sending 10; then echo "reached run=$e2e_run_id bytes=$e2e_run_bytes status=$e2e_run_status";'
        . " else echo 'not reached'; fi\ne2e_finish\n", ['E2E_NAME' => $name, 'PATH' => "$work/bin:" . getenv('PATH'),
        // A wait that never gets there ends within 20 s, not after the two hours of a real run.
        'SECBK_E2E_BACKUP_TIMEOUT' => '20']);
    secbk_e2e_expect("the wait for the point: $label", str_contains($output, $words)
        && ($words !== 'not reached' || str_contains($output, 'the run ended (aborted) before it got there')),
        "exit $exit: $output");
}
// The load waits until the run has sent a chunk (here 1000 bytes) in the stage files, counted from the bytes it had when it
// was first seen in that stage: what it sent in the stage db is not counted.
$list = implode(' ', array_map('escapeshellarg', ['2|db|active||500|10000', '2|db|active||500|10000', '2|db|active||500|10000',
    '2|files|active||600|10000', '2|files|active||1000|10000', '2|files|active||1700|10000']));
[$exit, $output] = secbk_e2e_lib("$rowpsql\ne2e_test_rows=($list)\ne2e_begin load r1-sending-files\n"
    . "e2e_after_id=1; sleep $commandlength & e2e_pid=\$!\n"
    . 'if e2e_wait_sending 0 1 1000; then echo "reached bytes=$e2e_run_bytes from=$e2e_files_from"; fi;'
    . ' kill "$e2e_pid"; e2e_finish',
    ['E2E_NAME' => 'r1-sending-files', 'SECBK_E2E_BACKUP_TIMEOUT' => '20']);
secbk_e2e_expect('the wait for a chunk of the files counts from the start of the stage files',
    str_contains($output, 'reached bytes=1700 from=600'), "exit $exit: $output");
$left = secbk_e2e_left("~^sleep $commandlength\$~");
secbk_e2e_expect('the commands of the waits are stopped by the trap', $left === [], implode("\n", $left));

// lib.sh: the secret of the test endpoints reaches sec.php only. A php on the PATH stands in for sec.php, cli/backup.php and
// enroll.php, and writes down whether it got the secret; every other call goes to the real php.
@mkdir("$work/bin-secret", 0777, true);
@mkdir("$work/storage/moodle", 0777, true);
file_put_contents("$work/bin-secret/php", "#!/bin/sh\ncase \"\$*\" in\n"
    . "    *sec.php*) echo \"sec.php \${SECBK_E2E_SECRET:-none}\" >> \"\$E2E_SEEN\"; exit 0 ;;\n"
    . "    *cli/backup.php*) echo \"cli/backup.php \${SECBK_E2E_SECRET:-none}\" >> \"\$E2E_SEEN\"; exit 0 ;;\n"
    // enroll.php reads its standard input, as the real one does: the writer of the pipe must not lose its reader.
    . "    *enroll.php*) cat > /dev/null; echo \"enroll.php \${SECBK_E2E_SECRET:-none}\" >> \"\$E2E_SEEN\"; exit 0 ;;\n"
    . "esac\nexec " . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
chmod("$work/bin-secret/php", 0755);
$seen = "$work/out/secret-seen.txt";
[$exit, $output] = secbk_e2e_lib('e2e_begin normal r1-secret scenario; e2e_log="$e2e_logs/backup-1.log";'
    . ' e2e_sec inspect --site=11111111-2222-4333-8444-555555555555 --out="$e2e_logs/inspect.json";'
    . ' e2e_launch_backup --now; wait "$e2e_pid"; e2e_enroll; e2e_finish',
    ['PATH' => "$work/bin-secret:" . getenv('PATH'), 'E2E_SEEN' => $seen, 'SECBK_E2E_SECRET' => 'secret-for-the-test-only',
        'SECBK_E2E_BASE_URL' => 'https://api.example.test/base']);
$lines = @file($seen, FILE_IGNORE_NEW_LINES) ?: [];
secbk_e2e_expect('the secret goes to sec.php, and not to cli/backup.php or enroll.php', $exit === 0
    && $lines === ['sec.php secret-for-the-test-only', 'cli/backup.php none', 'enroll.php none'],
    "exit $exit: $output\n" . implode("\n", $lines));

// lib.sh: the wait for the recount of the service is 1200 seconds unless SECBK_E2E_RECOUNT_WAIT says otherwise.
[$exit, $output] = secbk_e2e_lib('echo "recount $e2e_recount_wait"');
secbk_e2e_expect('the wait for the recount is 1200 s', trim($output) === 'recount 1200', $output);
[$exit, $output] = secbk_e2e_lib('echo "recount $e2e_recount_wait"', ['SECBK_E2E_RECOUNT_WAIT' => '5']);
secbk_e2e_expect('the wait for the recount can be set', trim($output) === 'recount 5', $output);

// lib.sh: the order of the steps of the scenarios. The steps that reach the site, the service, the firewall and other
// processes are replaced by stubs that write down their calls; the database of the site is a file with the record of the
// latest run, which psql prints, so that the real e2e_backup_start, e2e_read_run, e2e_stopped_run and e2e_read_sent read
// the runs as they would. Each "end" is what one call of cli/backup.php ends with: its exit code, and the status and the
// reason that its run has then. E2E_NEW_RUN_ON_CONTINUE makes the command without --now start a run of its own;
// E2E_DB_GONE makes the database unreadable after the last end. storage_php answers the settings of Moodle (cfg.php) from a
// file; E2E_BUDGET_READ makes it read another value back.
$stubs = <<<'BASH'
e2e_test_calls="$SECBK_E2E_OUT/calls-$E2E_NAME.txt"
e2e_test_db="$SECBK_E2E_OUT/db-$E2E_NAME.txt"
: > "$e2e_test_db"
e2e_test_call() { printf '%s\n' "$*" >> "$e2e_test_calls"; }
psql() {
    if [ -e "$e2e_test_db.gone" ]; then echo "the database is gone" >&2; return 2; fi
    cat "$e2e_test_db"
}
e2e_test_row() { printf '%s|%s|%s|%s|%s|%s\n' "$@" > "$e2e_test_db"; }
e2e_test_field() { cut -d '|' -f "$1" "$e2e_test_db"; }
e2e_launch_backup() {
    e2e_test_call "start $(basename "$e2e_log") $*"
    e2e_pid=4242
    if [ "${1:-}" = --now ] || [ "${E2E_NEW_RUN_ON_CONTINUE:-}" = yes ]; then
        e2e_test_row $(( $(e2e_test_field 1) + 1 )) start active '' 0 10000
    fi
}
e2e_wait_sending() {
    e2e_test_call "wait_sending $*"
    e2e_test_row "$(e2e_test_field 1)" files active '' $(( 10000 * $1 / 100 + 1 )) 10000
    e2e_files_from="${E2E_FILES_FROM:-0}"
    e2e_read_run
}
e2e_wait_exit() {
    local status code stage
    e2e_test_call "wait_exit $1"
    read -r e2e_exit status code <<< "${e2e_test_ends[0]}"
    e2e_test_ends=("${e2e_test_ends[@]:1}")
    if [ "$code" = - ]; then code=''; fi
    stage="$(e2e_test_field 2)"
    if [ "$status" = completed ]; then stage=done; fi
    e2e_test_row "$(e2e_test_field 1)" "$stage" "$status" "$code" "$(e2e_test_field 5)" 10000
    if [ "${#e2e_test_ends[@]}" -eq 0 ] && [ "${E2E_DB_GONE:-}" = yes ]; then : > "$e2e_test_db.gone"; fi
}
e2e_alive() { return 1; }
storage_php() {
    local all="$*"
    e2e_test_call "storage_php $all"
    case "$all" in
        *cfg.php*--set=*) echo "${all##*--set=}" > "$e2e_test_db.budget" ;;
        *cfg.php*) if [ -n "${E2E_BUDGET_READ:-}" ]; then echo "$E2E_BUDGET_READ"; else cat "$e2e_test_db.budget"; fi ;;
    esac
}
kill() { e2e_test_call "kill $*"; }
e2e_s3_block() { e2e_test_call s3_block; e2e_s3_blocked=yes; }
e2e_s3_unblock() { if [ "$e2e_s3_blocked" = yes ]; then e2e_test_call s3_unblock; e2e_s3_blocked=no; fi; }
e2e_load_start() { e2e_test_call load_start; e2e_load_pids=(1); }
e2e_load_stop() { if [ "${#e2e_load_pids[@]}" -gt 0 ]; then e2e_test_call load_stop; e2e_load_pids=(); fi; }
e2e_wait_stopped() { e2e_test_call "wait_stopped $2 | $3"; }
e2e_wait_recount() { e2e_test_call wait_recount; }
e2e_inspect() { e2e_test_call "inspect $(basename "$1")"; }
e2e_verdict() { e2e_test_call "verdict $1 | ${*:3}"; }
e2e_final() { e2e_test_call "final $*"; }
e2e_inspected_bytes() { echo 1000; }
e2e_check_resend() { e2e_test_call "resend $*"; }
BASH;

/**
 * Runs a scenario of lib.sh with the stubs, and returns [exit code, output, the calls, the result].
 *
 * @param string $name The name of the site.
 * @param string $scenario The call of the scenario, such as "e2e_scenario_abort".
 * @param string[] $ends What the calls of cli/backup.php end with, in their order.
 * @param array $env Variables of the environment to set.
 * @return array
 */
function secbk_e2e_scenario(string $name, string $scenario, array $ends, array $env = []): array {
    global $stubs, $work;
    $list = implode(' ', array_map(static fn(string $end): string => escapeshellarg($end), $ends));
    [$exit, $output] = secbk_e2e_lib("$stubs\ne2e_test_ends=($list)\ne2e_begin test $name\n$scenario\ne2e_finish\n",
        ['E2E_NAME' => $name] + $env);
    $calls = array_map('trim', @file("$work/out/calls-$name.txt", FILE_IGNORE_NEW_LINES) ?: []);
    return [$exit, $output, $calls, secbk_e2e_result($name) ?? []];
}

[$exit, $output, $calls, $result] = secbk_e2e_scenario('r1-abort', 'e2e_scenario_abort',
    ['1 aborted upload_failed', '137 active -', '0 completed -']);
// The budget of the temporary files is set to 128 MB and read back before the first cli/backup.php.
$wanted = ['storage_php admin/cli/cfg.php --component=tool_secbackup --name=tempbudgetmb --set=128',
    'storage_php admin/cli/cfg.php --component=tool_secbackup --name=tempbudgetmb',
    'start backup-1.log --now', 'wait_sending 10', 's3_block',
    'wait_exit 3600', 's3_unblock',
    'wait_stopped upload_failed | (b) after the stop', 'wait_recount', 'start backup-2.log --now', 'wait_sending 10',
    'kill -9 4242', 'wait_exit 60', 'inspect inspect-killed.json', 'verdict (a) after kill -9 | --stopped=open',
    'start backup-3.log', 'wait_exit 7200', 'final --runs=2 --failed=upload_failed', 'resend 1000 1001 1000'];
secbk_e2e_expect('abort: (b) S3 refused, the stop, the recount, then (a) kill -9 and the same run to its end', $exit === 0
    && $calls === $wanted && ($result['reasons'] ?? null) === [], "exit $exit: $output\n" . implode("\n", $calls));
$aborts = [
    'the run that S3 did not stop' => [['0 completed -'], [], '(b) the refused connections to S3 did not stop the run'],
    'a kill that did not end the command' => [['1 aborted upload_failed', '0 completed -', '0 completed -'], [],
        '(a) cli/backup.php ended with 0 after kill -9, expected 137'],
    'the run after the kill that did not complete' => [['1 aborted upload_failed', '137 active -', '1 aborted upload_failed'],
        [], '(a) the run that went on is aborted, expected completed'],
    'the command after the kill that failed' => [['1 aborted upload_failed', '137 active -', '3 active -'], [],
        '(a) cli/backup.php without --now ended with 3, expected 0'],
    'the command after the kill that started another run' => [['1 aborted upload_failed', '137 active -', '0 completed -'],
        ['E2E_NEW_RUN_ON_CONTINUE' => 'yes'], '(a) cli/backup.php went on with run 3, expected run 2'],
];
foreach ($aborts as $label => [$ends, $env, $words]) {
    [$exit, $output, $calls, $result] = secbk_e2e_scenario('r1-abort-' . md5($label), 'e2e_scenario_abort', $ends, $env);
    secbk_e2e_expect("abort: $label names its step", $exit === 1
        && str_contains(implode("\n", $result['reasons'] ?? []), $words), "exit $exit: $output");
}
// A database that cannot be read at the end of (a) gives that reason, and not a second one about the ID of the run.
[$exit, $output, $calls, $result] = secbk_e2e_scenario('r1-abort-db', 'e2e_scenario_abort',
    ['1 aborted upload_failed', '137 active -', '0 completed -'], ['E2E_DB_GONE' => 'yes']);
$reasons = implode("\n", $result['reasons'] ?? []);
secbk_e2e_expect('abort: a database that cannot be read after (a) gives only that reason', $exit === 1
    && str_contains($reasons, '(a) the run that went on: the database of the site cannot be read')
    && !str_contains($reasons, 'went on with run') && !str_contains($reasons, 'expected completed'), "exit $exit: $reasons");
[$exit, $output, $calls, $result] = secbk_e2e_scenario('r1-load', 'e2e_scenario_load',
    ['1 aborted server_busy', '0 completed -']);
$wanted = ['storage_php admin/cli/cfg.php --component=tool_secbackup --name=tempbudgetmb --set=128',
    'storage_php admin/cli/cfg.php --component=tool_secbackup --name=tempbudgetmb',
    'start backup-1.log --now', 'wait_sending 0 1 33554432',
    'load_start', 'wait_exit 7200', 'load_stop',
    'wait_stopped server_busy | after the stop', 'wait_recount', 'start backup-2.log --now', 'wait_exit 7200',
    'final --runs=2 --failed=server_busy', 'resend 1000 0 1000'];
secbk_e2e_expect('load: the load after a chunk of the files, the stop, the recount and the next run', $exit === 0
    && $calls === $wanted && ($result['reasons'] ?? null) === [] && ($result['numbers']['files_sent_before_stop'] ?? null) === 1,
    "exit $exit: $output\n" . implode("\n", $calls) . json_encode($result));
[$exit, $output, $calls, $result] = secbk_e2e_scenario('r1-load-nofiles', 'e2e_scenario_load',
    ['1 aborted server_busy', '0 completed -'], ['E2E_FILES_FROM' => '1']);
secbk_e2e_expect('load: a run that the load stopped before it sent anything in the stage files fails', $exit === 1
    && ($result['numbers']['files_sent_before_stop'] ?? null) === 0 && str_contains(implode("\n", $result['reasons'] ?? []),
    'the load stopped the run before it sent anything in the stage files'), "exit $exit: $output " . json_encode($result));
foreach (['e2e_scenario_load' => 'load', 'e2e_scenario_abort' => 'abort'] as $function => $label) {
    [$exit, $output, $calls, $result] = secbk_e2e_scenario("r1-budget-$label", $function, ['0 completed -'],
        ['E2E_BUDGET_READ' => '0']);
    secbk_e2e_expect("$label: a budget that does not read back as 128 MB stops the scenario before cli/backup.php", $exit === 1
        && str_contains(implode("\n", $result['reasons'] ?? []), 'the budget of the temporary files of the plugin is 0 MB, not 128')
        && !in_array('start backup-1.log --now', $calls, true), "exit $exit: $output\n" . implode("\n", $calls));
}
[$exit, $output, $calls, $result] = secbk_e2e_scenario('r1-load-busy', 'e2e_scenario_load', ['0 completed -']);
secbk_e2e_expect('load: a run that the load did not stop', $exit === 1
    && str_contains(implode("\n", $result['reasons'] ?? []), 'the load did not stop the run'), "exit $exit: $output");
[$exit, $output, $calls, $result] = secbk_e2e_scenario('r1-normal', 'e2e_scenario_normal', ['0 completed -']);
secbk_e2e_expect('normal: one run and the check at the end', $exit === 0
    && $calls === ['start backup-1.log --now', 'wait_exit 7200', 'final --runs=1'],
    "exit $exit: $output\n" . implode("\n", $calls));
[$exit, $output, $calls, $result] = secbk_e2e_scenario('r1-c01', 'e2e_scenario_concurrent $(( $(date +%s) - 30 ))',
    ['0 completed -']);
$late = $result['numbers']['start_late'] ?? -1;
secbk_e2e_expect('concurrent: a start in the past begins at once and says how late it is', $exit === 0
    && ($calls[0] ?? '') === 'start backup-1.log --now' && $late >= 30 && $late < 40, "exit $exit: $output");
secbk_e2e_expect('concurrent: the budget of the temporary files stays the default', !preg_grep('/cfg\.php/', $calls),
    implode("\n", $calls));

// run.sh refuses wrong arguments before anything else (exit 2), and runs only on a disposable runner: arguments that pass
// every check reach that guard, which stops the script here, since the tests run without GITHUB_ACTIONS.
$now = time();
$refused = [
    'no scenario' => [],
    'an unknown scenario' => ['bogus', '--name=r1-x', '--files-gb=1'],
    'a name in upper case' => ['normal', '--name=R1-normal', '--files-gb=1'],
    'a name that starts with a hyphen' => ['normal', '--name=-r1', '--files-gb=1'],
    'a name that ends with a hyphen' => ['normal', '--name=r1-', '--files-gb=1'],
    'a name of 41 characters' => ['normal', '--name=' . str_repeat('a', 41), '--files-gb=1'],
    'no name' => ['normal', '--files-gb=1'],
    'no size' => ['normal', '--name=r1-normal'],
    'a size of 0' => ['normal', '--name=r1-normal', '--files-gb=0.0'],
    'a size that is not a number' => ['normal', '--name=r1-normal', '--files-gb=1e3'],
    'concurrent without a start' => ['concurrent', '--name=r1-c01', '--files-gb=0.8'],
    'a start for another scenario' => ['normal', '--name=r1-normal', '--files-gb=1', "--start-at=$now"],
    'a start more than 3 hours away' => ['concurrent', '--name=r1-c01', '--files-gb=0.8', '--start-at=' . ($now + 10900)],
    'a start that is not a time' => ['concurrent', '--name=r1-c01', '--files-gb=0.8', '--start-at=soon'],
    'an unknown option' => ['normal', '--name=r1-normal', '--files-gb=1', '--fast'],
    'a part that it does not know' => ['normal', '--name=r1-normal', '--files-gb=1', '--part=all'],
    'the scenario part of data-only' => ['data-only', '--name=r1-data', '--files-gb=0.2', '--part=scenario'],
];
foreach ($refused as $label => $args) {
    [$exit, $output] = secbk_e2e_command(array_merge(['bash', "$e2e/run.sh"], $args));
    secbk_e2e_expect("run.sh refuses $label", $exit === 2 && !str_contains($output, 'disposable runner'), "exit $exit: $output");
}
$accepted = [
    'normal' => ['normal', '--name=r1-normal', '--files-gb=10'],
    'a name of 40 characters' => ['normal', '--name=' . str_repeat('a', 40), '--files-gb=0.5'],
    'concurrent that starts now' => ['concurrent', '--name=r1-c01', '--files-gb=0.8', "--start-at=$now"],
    'concurrent with a start in the past' => ['concurrent', '--name=r1-c01', '--files-gb=0.8', '--start-at=' . ($now - 600)],
    'concurrent that starts in nearly 3 hours' => ['concurrent', '--name=r1-c01', '--files-gb=0.8',
        '--start-at=' . ($now + 10000)],
    'data-only with skips' => ['data-only', '--name=r1-data', '--files-gb=0.2', '--skip=mod_videoassessment',
        '--skip=block_autoattend,mod_autoattendmod'],
    'the site part' => ['normal', '--name=r1-normal', '--files-gb=10', '--part=site'],
    'the scenario part' => ['concurrent', '--name=r1-c01', '--files-gb=0.8', "--start-at=$now", '--part=scenario'],
    'the site part of data-only' => ['data-only', '--name=r1-data', '--files-gb=0.2', '--part=site'],
];
foreach ($accepted as $label => $args) {
    [$exit, $output] = secbk_e2e_command(array_merge(['bash', "$e2e/run.sh"], $args));
    secbk_e2e_expect("run.sh takes the arguments of $label", $exit === 2 && str_contains($output, 'disposable runner'),
        "exit $exit: $output");
}

// mask.sh: the URL of the service and its host name (as written and in lower case) are printed only inside ::add-mask::
// commands, which the runner of GitHub takes out of the log; anywhere else it prints nothing of them. Without the URL (an
// environment without its secret) it stops the job.
$mask = static fn(array $env): array => secbk_e2e_command(['bash', "$e2e/mask.sh"], $env);
$url = 'https://Api.Example.test:8443/base/path';
[$exit, $output] = $mask(['GITHUB_ACTIONS' => 'true', 'SECBK_E2E_BASE_URL' => $url]);
secbk_e2e_expect('mask.sh masks the URL and its host name, as written and in lower case',
    $exit === 0 && $output === "::add-mask::$url\n::add-mask::Api.Example.test\n::add-mask::api.example.test\n",
    "exit $exit: $output");
[$exit, $output] = $mask(['GITHUB_ACTIONS' => 'true', 'SECBK_E2E_BASE_URL' => 'https://api.example.test']);
secbk_e2e_expect('mask.sh masks a host name in lower case once',
    $exit === 0 && $output === "::add-mask::https://api.example.test\n::add-mask::api.example.test\n", "exit $exit: $output");
[$exit, $output] = $mask(['SECBK_E2E_BASE_URL' => $url]);
secbk_e2e_expect('mask.sh runs on GitHub only, and then prints nothing of the URL',
    $exit === 2 && !str_contains(strtolower($output), 'example'), "exit $exit: $output");
foreach (['an empty URL' => ['SECBK_E2E_BASE_URL' => ''], 'no URL' => []] as $label => $env) {
    [$exit, $output] = $mask(['GITHUB_ACTIONS' => 'true'] + $env);
    secbk_e2e_expect("mask.sh stops the job with $label",
        $exit === 1 && str_contains($output, 'SECBK_E2E_BASE_URL') && !str_contains($output, '::'), "exit $exit: $output");
}

proc_terminate($fake);
proc_close($fake);
// Development tooling may run commands; the plugin itself never does.
\exec('rm -rf ' . escapeshellarg($work));
echo "staging end-to-end scripts: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
