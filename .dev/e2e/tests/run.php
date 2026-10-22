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
// verdict.php and summary.php read files that the tests write in a folder of their own in the temporary folder.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$e2e = dirname(__DIR__);
$pass = 0;
$fail = 0;
$work = sys_get_temp_dir() . '/secbk-e2e-test-' . getmypid();
mkdir($work, 0700, true);

/**
 * Runs a PHP script of .dev/e2e and returns [exit code, output and errors together].
 *
 * The variables of the staging test are taken out of the environment of this process, so that the values of a shell never
 * reach a test; $env sets the ones that the test needs.
 *
 * @param string $script sec.php, verdict.php or summary.php.
 * @param string[] $args The arguments.
 * @param array $env Variables of the environment to set.
 * @return array
 */
function secbk_e2e_run(string $script, array $args, array $env = []): array {
    global $e2e;
    $environment = array_filter(getenv(), static fn(string $name): bool => !str_starts_with($name, 'SECBK_E2E_'),
        ARRAY_FILTER_USE_KEY);
    // Development tooling may run commands; the plugin itself never does.
    $process = proc_open(array_merge([PHP_BINARY, "$e2e/$script"], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, array_merge($environment, $env));
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
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
                'requested_bytes' => 3000, 'has_receipt' => true, 'created_at' => '2026-10-23T01:30:00+00:00',
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
    secbk_e2e_site($folder, sprintf('r1-c%02d', $n), ['scenario' => 'concurrent', 'retries' => ['waits' => 2, 'failed_steps' => 1]],
        ['objects' => ['bytes' => 900000000]]);
}
secbk_e2e_site($folder, 'r1-data', ['scenario' => 'data-only', 'sent_bytes' => 0], null);
[$exit, $output] = secbk_e2e_run('summary.php', [$folder, '--limit-gb=30']);
secbk_e2e_expect('sites that all passed pass; the scenario data-only needs no inspection', $exit === 0
    && substr_count($output, '| concurrent |') === 10 && str_contains($output, '| data-only |')
    && str_contains($output, '9.000 GB'), "exit $exit: $output");
secbk_e2e_expect('the summary shows the waits and failed steps of the runs', str_contains($output, '2 / 1'), $output);
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

proc_terminate($fake);
proc_close($fake);
// Development tooling may run commands; the plugin itself never does.
\exec('rm -rf ' . escapeshellarg($work));
echo "staging end-to-end scripts: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
