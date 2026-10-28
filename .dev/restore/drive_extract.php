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
 * Development only: plays the restore host for the extraction (cli/restore.php --extract) of a folder that make_fixture.php
 * wrote.
 *
 * Its standard input is the standard output of the extraction, and its standard output the standard input of the extraction
 * (restore.sh joins them with two named pipes). It reads the one-time public key of the extraction, seals the secret key of
 * the site (site-secret-key) to it, as the backup service does, gives the keys and then the input: input.json of the folder
 * with each relative path made absolute against the folder, written to restore/input.json. It reads the progress and the
 * summary of the extraction, and checks the summary against what fixture.json expects.
 *
 * The secret key and the sealed key go to the extraction only: what this script prints on its error stream (the progress, the
 * summary or the code of a failure) holds no key.
 *
 * Usage: php drive_extract.php --fixture=<dir>   (an absolute path; the extraction writes under <dir>/restore)
 * Ends with 0 when the extraction ends with its summary and the summary is the expected one, else with 1.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

/**
 * Prints a line on the error stream.
 *
 * @param string $line The line.
 */
function secbk_dev_say(string $line): void {
    fwrite(STDERR, $line . "\n");
}

/**
 * Stops with a message on the error stream and the exit code 1.
 *
 * @param string $message The message.
 */
function secbk_dev_fail(string $message): void {
    secbk_dev_say('drive_extract: ' . $message);
    exit(1);
}

/**
 * Reads the next line of the extraction as JSON.
 *
 * @return array The message.
 */
function secbk_dev_receive(): array {
    $line = fgets(STDIN);
    if ($line === false) {
        secbk_dev_fail('the extraction ended without its summary');
    }
    $message = json_decode($line, true);
    if (!is_array($message) || !is_string($message['type'] ?? null)) {
        secbk_dev_fail('the extraction wrote a line that is not a message');
    }
    if ($message['type'] === 'error') {
        secbk_dev_fail('the extraction failed with ' . json_encode($message['code'] ?? null));
    }
    return $message;
}

/**
 * Writes a line to the extraction.
 *
 * @param array $message The message.
 */
function secbk_dev_send(array $message): void {
    $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (fwrite(STDOUT, $line) !== strlen($line) || !fflush(STDOUT)) {
        secbk_dev_fail('the extraction does not read its input');
    }
}

$options = getopt('', ['fixture:']);
$fixture = rtrim((string) ($options['fixture'] ?? ''), '/');
if (!str_starts_with($fixture, '/') || !is_file($fixture . '/input.json') || !is_file($fixture . '/fixture.json')) {
    secbk_dev_fail('usage: php drive_extract.php --fixture=<absolute folder that make_fixture.php wrote>');
}
$input = json_decode((string) file_get_contents($fixture . '/input.json'), true);
$expected = json_decode((string) file_get_contents($fixture . '/fixture.json'), true)['expected'] ?? null;
if (!is_array($input) || !is_array($expected)) {
    secbk_dev_fail('input.json or fixture.json cannot be read');
}
// The paths of the template are relative to the folder; the extraction takes absolute ones only.
$absolute = static fn(string $path): string => str_starts_with($path, '/') ? $path : $fixture . '/' . $path;
$input['manifest']['path'] = $absolute($input['manifest']['path']);
foreach (['chunks_dir', 'target_root', 'target_dataroot', 'staging_dir'] as $member) {
    $input[$member] = $absolute($input[$member]);
}
// The restore host makes the folders that the extraction writes into: it writes nothing outside them, not even them.
foreach (['target_root' => 0755, 'target_dataroot' => 02770, 'staging_dir' => 0750] as $member => $mode) {
    if (!is_dir($input[$member]) && (!mkdir($input[$member], 0700, true) || !chmod($input[$member], $mode))) {
        secbk_dev_fail("the folder of $member cannot be made");
    }
}
if (!is_dir($fixture . '/restore') && !mkdir($fixture . '/restore', 0700)) {
    secbk_dev_fail('the folder restore cannot be made');
}
$inputfile = $fixture . '/restore/input.json';
file_put_contents($inputfile, json_encode($input, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");

$hello = secbk_dev_receive();
$ephemeral = base64_decode((string) ($hello['key'] ?? ''), true);
if (
    $hello['type'] !== 'ephemeral_public_key' || !is_string($ephemeral)
    || strlen($ephemeral) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
) {
    secbk_dev_fail('the first line of the extraction is not its public key');
}
$secret = base64_decode(trim((string) file_get_contents($fixture . '/site-secret-key')), true);
if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
    secbk_dev_fail('site-secret-key is not a secret key');
}
$sealed = base64_encode(sodium_crypto_box_seal($secret, $ephemeral));
sodium_memzero($secret);
secbk_dev_send(['type' => 'site_keys', 'sealed' => [$sealed]]);
sodium_memzero($sealed);
$ready = secbk_dev_receive();
if ($ready['type'] !== 'keys_ready' || ($ready['count'] ?? null) !== 1) {
    secbk_dev_fail('the extraction did not take the key');
}
secbk_dev_send(['type' => 'go', 'input' => $inputfile]);

$started = time();
$lines = 0;
while (true) {
    $message = secbk_dev_receive();
    if ($message['type'] === 'done') {
        break;
    }
    if (
        $message['type'] !== 'progress' || ($message['stage'] ?? null) !== 'extracting'
        || ($message['unit'] ?? null) !== 'entries'
    ) {
        secbk_dev_fail('the extraction wrote a message that is not progress');
    }
    // The first line, then one in ten: the extraction tells how far it is every 1,000 entries or 5 seconds.
    if ($lines++ % 10 === 0) {
        secbk_dev_say(sprintf('extracting: %d of %d entries (%d s)', $message['done'], $message['total'], time() - $started));
    }
}
fclose(STDOUT);
$summary = $message['summary'] ?? null;
secbk_dev_say('extracted in ' . (time() - $started) . ' s: ' . json_encode($summary, JSON_UNESCAPED_SLASHES));
if (stream_get_contents(STDIN) !== '') {
    secbk_dev_fail('the extraction wrote more after its summary');
}
if ($summary !== $expected) {
    secbk_dev_fail('the summary is not the one that fixture.json expects: ' . json_encode($expected, JSON_UNESCAPED_SLASHES));
}
secbk_dev_say('summary: as fixture.json expects');
